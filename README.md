# SMWApprovedRevsDataSync
MediaWiki Extension: SMW &amp; Approved Revs Data Syncing fix.

A specialized bridge extension for MediaWiki 1.43+ that reconciles **ApprovedRevs** revision states with **Semantic MediaWiki (SMW)** property tables.

## The Problem
By default, Semantic MediaWiki updates its property tables based on the *latest* revision saved to the database. If a user saves a "Draft" (unapproved) version of a page, SMW typically updates its data to reflect that draft. This causes `#ask` queries and property displays to show "unverified" data, even if an older version is marked as "Approved."

## The Solution: "Truth Spoofing"
This extension intercepts the approval/unapproval workflow and performs a **Data Truth Sync**. 
1. It retrieves the content of the **Approved Revision**.
2. It "spoofs" the Revision ID of that content to match the **Latest Revision ID**.
3. It force-injects this data into the SMW Store.

This tricks MediaWiki and SMW into believing the "Approved" content is the current "Database Truth," ensuring all property tables remain 100% aligned with the approved state.

## Features
- **Modern UI:** Provides a blurred backdrop overlay with a 3-stage progress bar (Prepare → Align → Complete).
- **Citizen Skin Compatibility:** Uses CSS variables to automatically support Light and Dark modes.
- **Cache Integrity:** Automatically triggers a server-side purge after syncing to ensure the UI reflects the new data immediately.
- **MW 1.43 Optimized:** Uses modern namespaces and `extension.json` registration.

## Installation

1. Copy the `SMWApprovedRevsDataSync` folder to your `extensions/` directory.
2. Ensure the folder name is exactly `SMWApprovedRevsDataSync`.
3. Add the following to your `LocalSettings.php` **after** both SMW and ApprovedRevs:

##Version
1.0 Initial Release
  Same Page Data Alignment
1.1 Beta (not public release)
  Table Alignment accross multiple pages

```php
wfLoadExtension( 'SMWApprovedRevsDataSync' );
