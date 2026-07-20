![alt XOOPS CMS](https://xoops.org/images/logo.png)
## XOOPS DebugBar module for [XOOPS CMS 2.7.0+](https://xoops.org)
[![XOOPS CMS Module](https://img.shields.io/badge/XOOPS%20CMS-Module-blue.svg)](https://xoops.org)
[![Software License](https://img.shields.io/badge/license-GPL-brightgreen.svg?style=flat)](https://www.gnu.org/licenses/gpl-2.0.html)

[![Scrutinizer Code Quality](https://img.shields.io/scrutinizer/g/mambax7/debugbar.svg?style=flat)](https://scrutinizer-ci.com/g/mambax7/debugbar/?branch=master)
[![Latest Pre-Release](https://img.shields.io/github/tag/XoopsModules27x/debugbar.svg?style=flat)](https://github.com/XoopsModules27x/debugbar/tags/)
[![Latest Version](https://img.shields.io/github/release/XoopsModules27x/debugbar.svg?style=flat)](https://github.com/XoopsModules27x/debugbar/releases/)

# XOOPS DebugBar

DebugBar is a developer-focused diagnostics and performance module for XOOPS 2.7. It adds an administrator-only PHP DebugBar toolbar to rendered pages and provides protected administration pages for performance analytics, logs, system diagnostics, and Xdebug profiles.

The module is designed to fail closed: anonymous visitors never receive diagnostic output, optional integrations are capability-detected, and disabling or removing an optional tool does not prevent DebugBar from loading.

## Requirements

- XOOPS 2.7.0 or newer
- PHP 8.2 or newer
- MySQL 8.0 or a compatible MariaDB release
- `php-debugbar/php-debugbar` available through the XOOPS Composer autoloader
- A writable module assets directory during installation or module update


## License

GNU General Public License 2.0 or later. See the license information in `xoops_version.php` and the source-file headers.

## Credits

Based on the earlier XOOPS DebugBar integration by Richard Griffith and trabis, with subsequent XOOPS 2.7 compatibility, diagnostics, analytics, logging, and security work maintained by the XOOPS community.


**Content Publishing module** for [XOOPS CMS](https://xoops.org) for static/HTML content and dynamic articles stored and presented in a hierarchical manner


Please visit us on https://xoops.org

Current and upcoming "next generation" versions of XOOPS CMS are crafted on GitHub at: https://github.com/XOOPS