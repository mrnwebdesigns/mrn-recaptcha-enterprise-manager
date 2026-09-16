=== MRN reCAPTCHA Enterprise Manager ===
Contributors: mrnwebdesigns
Requires at least: 6.9
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creates and manages Google reCAPTCHA Enterprise website keys and synchronizes them with WPForms.

== Description ==

Provides the MRN Stack's code-locked Google reCAPTCHA Enterprise integration, including idempotent WPForms credential bootstrap for managed deployments.

== Changelog ==

= 0.1.2 =
* Add idempotent Stack bootstrap that reuses or creates the exact hostname key and fails closed on ambiguous or incomplete configuration.

= 0.1.1 =
* Add the initial managed reCAPTCHA Enterprise integration.
