#### ZFS Master for Unraid

[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://paypal.me/DML)

The ZFS Master plugin provides information and control over the ZFS Pools in your Unraid server. Available ZFS Pools are listed under the "Main/ZFSMaster" tab.

### About this Fork
This project is a fork of the original [ZFS Master plugin created by IkerSaint](https://github.com/IkerSaint/ZFS-Master-Unraid). All credit for the original design, architecture, and features goes to IkerSaint and contributors.

This fork has been updated and modernized to ensure full compatibility with modern Unraid releases (**Unraid 7.3.2+**):
- **PHP 8 Compatibility:** Resolved PHP 8 CLI syntax requirements (standard `<?php` tags) so the background monitor daemon runs reliably under Unraid 7.x.
- **CSRF Token Hardening:** Integrated CSRF token authentication across all WebGUI forms and AJAX endpoints in accordance with Unraid 7.3.2 security updates (CVE-2026-3838 patch).
- **OpenZFS 2.4 Inventory:** Uses machine-readable `zfs list`/`zfs get` output for datasets, volumes, user properties, and snapshots without channel-program limits.
- **Safe Administration:** Dataset, snapshot, encryption, and directory operations use argument-safe process execution with server-side validation and useful ZFS errors.
- **WebGUI Modernization:** Fixed header alignment and layout conflicts with the Unraid 7 navbar, and updated FontAwesome icon references.

### Requirements
* Unraid **7.3.2 or newer** with a native ZFS pool.

### Inventory delivery (2026.09.25.112)
Dataset and snapshot inventory now returns directly from the authenticated plugin
HTTP endpoint. It does not require the Unraid API, the Nchan inventory daemon, or
changes to `/tmp/publishPaused`. The Main page no longer starts the inventory daemon.
Nchan is only an optional live directory-copy progress feed; final action results
still return through HTTP.

Automatic refresh follows the configured interval after each request completes,
and pauses scans while the page is hidden. Manual mode does not scan on page load;
use Refresh or perform an action. Successful actions refresh the parent table,
including actions in popups. Lazy loading displays datasets first, then retrieves
snapshots one pool at a time. Read requests have a 45-second CLI budget and a
60-second browser timeout. Failures retain existing rows and mark them as stale.

Installation and removal no longer reload nginx. This fixes the plugin's dependency
on push delivery; it does not repair a stuck Unraid API/PM2 service. If publishing is
paused, the page reports that condition alongside the direct-inventory result.

### Support & Donations
If you find this updated fork helpful, donations are appreciated:
[![Donate with PayPal](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://paypal.me/DML)
