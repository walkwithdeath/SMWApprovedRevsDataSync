<?php

namespace SMWApprovedRevsDataSync;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use SMW\DIWikiPage;
use SMW\ParserData;
use SMW\StoreFactory;
use SMW\MediaWiki\Jobs\UpdateJob;
use ApprovedRevs;
use ContentHandler;
use ParserOptions;

class SyncEngine {

    public static function onRevisionApproved( $parser, $title ) {
        if ( !defined( 'SMW_VERSION' ) ) return true;
        \MediaWiki\Deferred\DeferredUpdates::addCallableUpdate( function () use ( $title ) {
            $job = new UpdateJob( $title );
            MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
        } );
        return true;
    }

    public static function onBeforePageDisplay( $out, $skin ) {
        $request = $out->getRequest();
        $title   = $out->getTitle();
        $action  = $request->getVal( 'action' );
        $stage   = $request->getVal( 'syncstage' );

        if ( $out->isPrintable() ) return true;

        $approvedRevId = ApprovedRevs::getApprovedRevID( $title );
        $latestRevId   = $title->getLatestRevID();
        $targetRevId   = $request->getVal( 'revsync' ) ?: ( $approvedRevId ?: $latestRevId );

        if ( in_array( $action, [ 'approve', 'unapprove' ] ) || $stage === '2' ) {
            
            if ( $stage === '2' ) {
                session_write_close();
                try {
                    $services = MediaWikiServices::getInstance();
                    $store = StoreFactory::getStore();
                    $revision = $services->getRevisionLookup()->getRevisionById( (int)$targetRevId );

                    if ( $revision ) {
                        $content = $revision->getContent( SlotRecord::MAIN );
                        $pOptions = ParserOptions::newFromUser( $out->getUser() );
                        $pOutput = $services->getParser()->parse( ContentHandler::getContentText( $content ), $title, $pOptions );
                        
                        // Truth Spoofing for MW 1.43
                        $pOutput->setCacheRevisionId( (int)$latestRevId );
                        
                        $parserData = new ParserData( $title, $pOutput );
                        $store->clearData( DIWikiPage::newFromTitle( $title ) );
                        $store->updateData( $parserData->getSemanticData() );
                    }
                    $title->invalidateCache();
                } catch ( \Throwable $e ) {
                    error_log( "[SMWApprovedRevsDataSync] ERROR: " . $e->getMessage() );
                }
            }

            $out->addHTML( self::getOverlayHtml( (int)$targetRevId ) );
            $out->addInlineScript( self::getOverlayJs( $title->getLinkURL(), $stage, (int)$targetRevId ) );
        }
        return true;
    }

    private static function getOverlayHtml( $targetRevId ) {
        return "
            <div id='smw-sync-overlay' style='position: fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.75); backdrop-filter: blur(5px); z-index:2147483647; display:flex; align-items:center; justify-content:center; font-family: sans-serif;'>
                <div style='background:var(--background-color-base, #fff); color:var(--color-base, #202122); padding:40px; border-radius:16px; width:440px; text-align:center; box-shadow: 0 20px 60px rgba(0,0,0,0.5); border: 1px solid var(--border-color-base, #a2a9b1);'>
                    <div style='display:inline-block; margin-bottom:20px; padding:6px 14px; background:var(--background-color-primary-subtle, #eaf3ff); color:var(--color-primary, #36c); border-radius:20px; font-size:10px; font-weight:bold; letter-spacing:1.5px; text-transform:uppercase;'>System Sync</div>
                    <div id='sync-headline' style='font-size:24px; margin-bottom:8px; font-weight:700;'>Preparing Sync</div>
                    <div style='font-size:14px; color:var(--color-base--subtle, #72777d); margin-bottom:30px;'>Synchronizing Revision <span style='font-family:monospace; font-weight:bold; color:var(--color-primary, #36c);'>#$targetRevId</span></div>
                    <div style='background:var(--background-color-neutral, #eaecf0); height:8px; border-radius:4px; margin-bottom:15px; overflow:hidden;'>
                        <div id='sync-bar' style='background:var(--color-primary, #36c); width:0%; height:100%; transition:width 0.4s ease, background 0.3s ease;'></div>
                    </div>
                    <div id='sync-status' style='font-size:12px; font-style:italic; color:var(--color-base--subtle, #a2a9b1);'>Locating revision data...</div>
                </div>
            </div>";
    }

    private static function getOverlayJs( $url, $stage, $targetRevId ) {
        $data = json_encode( [
            'url' => $url,
            'stage' => $stage,
            'targetRevId' => $targetRevId,
            'purgeUrl' => $url . ( strpos( $url, '?' ) === false ? '?action=purge' : '&action=purge' )
        ] );

        return "
            (function(){
                var d = $data;
                var bar = document.getElementById('sync-bar'), status = document.getElementById('sync-status'), headline = document.getElementById('sync-headline');
                setTimeout(function(){
                    if(d.stage !== '2'){
                        bar.style.width = '40%';
                        setTimeout(function(){ window.location.href = d.url + (d.url.indexOf('?') === -1 ? '?' : '&') + 'syncstage=2&revsync=' + d.targetRevId; }, 500);
                    } else {
                        headline.textContent = 'Aligning Database Truth';
                        status.textContent = 'Updating semantic tables...';
                        bar.style.width = '85%';
                        setTimeout(function(){
                            bar.style.width = '100%'; bar.style.background = '#00af89';
                            headline.textContent = 'Sync Complete'; status.textContent = 'Finalizing and purging cache...';
                            setTimeout(function(){ fetch(d.purgeUrl, { method: 'POST' }).then(function() { window.location.href = d.url; }).catch(function() { window.location.href = d.url; }); }, 600); 
                        }, 800);
                    }
                }, 100);
            })();";
    }
}