#### ZFS Master for Unraid

[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://paypal.me/DML)

The ZFS Master plugin provides information and control over the ZFS Pools in your Unraid server. Available ZFS Pools are listed under the "Main/ZFSMaster" tab.

### About this Fork
This project is a fork of the original [ZFS Master plugin created by IkerSaint](https://github.com/IkerSaint/ZFS-Master-Unraid). All credit for the original design, architecture, and features goes to IkerSaint and contributors.

This fork has been updated and modernized to ensure full compatibility with modern Unraid releases (**Unraid 7.3.2+**):
- **PHP 8 Compatibility:** Resolved PHP 8 CLI syntax requirements (standard `<?php` tags) so the background monitor daemon runs reliably under Unraid 7.x.
- **CSRF Token Hardening:** Integrated CSRF token authentication across all WebGUI forms and AJAX endpoints in accordance with Unraid 7.3.2 security updates (CVE-2026-3838 patch).
- **OpenZFS 2.2+ Resiliency:** Updated `zpool list` parsing with robust tab-delimited OpenZFS CLI support and fallback handling.
- **Lua Engine Fixes:** Fixed snapshot destroy and rename syntax errors and wrapped property lookups in safe calls (`pcall`).
- **WebGUI Modernization:** Fixed header alignment and layout conflicts with the Unraid 7 navbar, and updated FontAwesome icon references.

### Requirements
* Unraid 6.12+ (native ZFS support) or Unraid 7.0+ (tested through **7.3.2**).
* Prior to Unraid 6.12, the *ZFS for Unraid 6* plugin was required.

### Support & Donations
If you find this updated fork helpful, donations are appreciated:
[![Donate with PayPal](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://paypal.me/DML)
