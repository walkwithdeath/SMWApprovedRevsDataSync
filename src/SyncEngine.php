<?php

namespace SMWApprovedRevsDataSync;

/**
 * SMWApprovedRevsDataSync - SyncEngine
 * * This class acts as a bridge between ApprovedRevs and Semantic MediaWiki.
 * It ensures that the SMW property tables (the 'Data Truth') always reflect 
 * the 'Approved' revision, even if a more recent 'Draft' revision exists.
 */

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

    /**
     * HOOK: ApprovedRevsRevisionApproved / Unapproved
     * * When a user manually clicks approve/unapprove, we push an UpdateJob
     * to the standard MediaWiki JobQueue. This ensures that even if the 
     * UI sync fails, the background system will eventually reconcile the data.
     */
    public static function onRevisionApproved( $parser, $title ) {
        if ( !defined( 'SMW_VERSION' ) ) return true;

        \MediaWiki\Deferred\DeferredUpdates::addCallableUpdate( function () use ( $title ) {
            $job = new UpdateJob( $title );
            MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
        } );
        return true;
    }

    /**
     * HOOK: BeforePageDisplay
     * * This is the main controller. It intercepts the page load during an 
     * approval action to show the UI and execute the 'Truth Spoofing' logic.
     */
    public static function onBeforePageDisplay( $out, $skin ) {
        $request = $out->getRequest();
        $title   = $out->getTitle();
        $action  = $request->getVal( 'action' );
        $stage   = $request->getVal( 'syncstage' );

        // Avoid triggering the overlay during print-view or API calls
        if ( $out->isPrintable() ) return true;

        // Determine which revision we are syncing.
        // Priority: 1. Manual override (?revsync=) 2. Approved Revision 3. Latest Revision
        $approvedRevId = ApprovedRevs::getApprovedRevID( $title );
        $latestRevId   = $title->getLatestRevID();
        $targetRevId   = $request->getVal( 'revsync' ) ?: ( $approvedRevId ?: $latestRevId );

        // Trigger the overlay if we are in an approval action or currently in Stage 2 of the sync
        if ( in_array( $action, [ 'approve', 'unapprove' ] ) || $stage === '2' ) {
            
            /**
             * STAGE 2: DATA RECONCILIATION
             * This runs on the second page load (after the JS redirect).
             */
            if ( $stage === '2' ) {
                // Close session to prevent 'Session Locks' from slowing down the fetch() purge
                session_write_close();

                try {
                    $services = MediaWikiServices::getInstance();
                    $store = StoreFactory::getStore();
                    $revision = $services->getRevisionLookup()->getRevisionById( (int)$targetRevId );

                    if ( $revision ) {
                        // 1. Fetch the raw content of the revision we want to make 'Truth'
                        $content = $revision->getContent( SlotRecord::MAIN );
                        $pOptions = ParserOptions::newFromUser( $out->getUser() );
                        
                        // 2. Parse the content into a ParserOutput object
                        $pOutput = $services->getParser()->parse( 
                            ContentHandler::getContentText( $content ), 
                            $title, 
                            $pOptions 
                        );
                        
                        /**
                         * TRUTH SPOOFING (Crucial for MW 1.43)
                         * We tell SMW that this ParserOutput belongs to the 'Latest' Revision ID.
                         * This prevents SMW from ignoring the update as 'Old Data'.
                         */
                        $pOutput->setCacheRevisionId( (int)$latestRevId );
                        
                        // 3. Prepare the Semantic Data container
                        $parserData = new ParserData( $title, $pOutput );
                        
                        // 4. Wipe existing properties for this page and inject the spoofed data
                        $store->clearData( DIWikiPage::newFromTitle( $title ) );
                        $store->updateData( $parserData->getSemanticData() );
                    }
                    
                    // Invalidate the HTML cache so the page displays the new data immediately
                    $title->invalidateCache();
                    
                } catch ( \Throwable $e ) {
                    // Log errors to the PHP error log for debugging
                    error_log( "[SMWApprovedRevsDataSync] ERROR: " . $e->getMessage() );
                }
            }

            // Injects the UI HTML and the controlling JavaScript into the Page Output
            $out->addHTML( self::getOverlayHtml( (int)$targetRevId ) );
            $out->addInlineScript( self::getOverlayJs( $title->getLinkURL(), $stage, (int)$targetRevId ) );
        }
        return true;
    }

    /**
     * Generates the HTML for the frosted-glass overlay.
     * Uses CSS variables compatible with the Citizen Skin and Dark Mode.
     */
    private static function getOverlayHtml( $targetRevId ) {
        return "
            <div id='smw-sync-overlay' style='position: fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.75); backdrop-filter: blur(5px); z-index:2147483647; display:flex; align-items:center; justify-content:center; font-family: sans-serif;'>
                <div style='background:var(--background-color-base, #fff); color:var(--color-base, #202122); padding:40px; border-radius:16px; width:440px; text-align:center; box-shadow: 0 20px 60px rgba(0,0,0,0.5); border: 1px solid var(--border-color-base, #a2a9b1);'>
                    
                    <div style='display:inline-block; margin-bottom:20px; padding:6px 14px; background:var(--background-color-primary-subtle, #eaf3ff); color:var(--color-primary, #36c); border-radius:20px; font-size:10px; font-weight:bold; letter-spacing:1.5px; text-transform:uppercase;'>System Sync</div>
                    
                    <div id='sync-headline' style='font-size:24px; margin-bottom:8px; font-weight:700;'>Preparing Sync</div>
                    
                    <div style='font-size:14px; color:var(--color-base--subtle, #72777d); margin-bottom:30px;'>
                        Synchronizing Revision <span style='font-family:monospace; font-weight:bold; color:var(--color-primary, #36c);'>#$targetRevId</span>
                    </div>
                    
                    <div style='background:var(--background-color-neutral, #eaecf0); height:8px; border-radius:4px; margin-bottom:15px; overflow:hidden;'>
                        <div id='sync-bar' style='background:var(--color-primary, #36c); width:0%; height:100%; transition:width 0.4s ease, background 0.3s ease;'></div>
                    </div>
                    
                    <div id='sync-status' style='font-size:12px; font-style:italic; color:var(--color-base--subtle, #a2a9b1);'>Locating revision data...</div>
                </div>
            </div>";
    }

    /**
     * Generates the JS to handle the multi-stage redirect and the cache purge.
     */
    private static function getOverlayJs( $url, $stage, $targetRevId ) {
        $data = json_encode( [
            'url' => $url,
            'stage' => $stage,
            'targetRevId' => $targetRevId,
            // Construct the Purge URL using action=purge
            'purgeUrl' => $url . ( strpos( $url, '?' ) === false ? '?action=purge' : '&action=purge' )
        ] );

        return "
            (function(){
                var d = $data;
                var bar = document.getElementById('sync-bar'), 
                    status = document.getElementById('sync-status'), 
                    headline = document.getElementById('sync-headline');
                
                // Slight delay to allow the CSS animation to initialize
                setTimeout(function(){
                    if(d.stage !== '2'){
                        // PHASE 1: Prepare the redirect to Stage 2
                        bar.style.width = '40%';
                        setTimeout(function(){ 
                            window.location.href = d.url + (d.url.indexOf('?') === -1 ? '?' : '&') + 'syncstage=2&revsync=' + d.targetRevId; 
                        }, 500);
                    } else {
                        // PHASE 2: Data is being processed by PHP in the background
                        headline.textContent = 'Aligning Database Truth';
                        status.textContent = 'Updating semantic tables...';
                        bar.style.width = '85%';
                        
                        setTimeout(function(){
                            // PHASE 3: Success state
                            bar.style.width = '100%'; 
                            bar.style.background = '#00af89'; // Success Green
                            headline.textContent = 'Sync Complete'; 
                            status.textContent = 'Finalizing and purging cache...';
                            
                            // Execute background POST purge to clear HTML cache
                            setTimeout(function(){ 
                                fetch(d.purgeUrl, { method: 'POST' }).then(function() { 
                                    window.location.href = d.url; 
                                }).catch(function() { 
                                    window.location.href = d.url; 
                                }); 
                            }, 600); 
                        }, 800);
                    }
                }, 100);
            })();";
    }
}