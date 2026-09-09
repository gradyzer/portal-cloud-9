=== Portal Cloud 9 ===
Contributors: briangradyzer
Tags: woocommerce, client portal, my account, loyalty, vendor dashboard
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 8.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 5.0
WC tested up to: 9.5

Replace the WooCommerce My Account page with a client portal. Loyalty points, vendor dashboard, order tracking, messaging and visitor analytics.

== Description ==

**Portal Cloud 9 is a WooCommerce client portal and My Account replacement** that gives every user role its own beautiful front end dashboard, no WordPress admin required. Customers track orders, earn reward points, and redeem coupons. Shop Managers manage products, handle inquiries, and cash out their sales rewards. Administrators oversee everything from a single control centre.

Built mobile first with a glassmorphic design, Portal Cloud 9 transforms a standard WooCommerce store into a fully featured vendor marketplace, membership area, and customer account portal, completely free, with all features unlocked and no external service connections required.

= Why Portal Cloud 9? =

Most WooCommerce stores send customers to the default My Account page, a plain and hard to use interface that drives people away. Portal Cloud 9 replaces it with a rich, role aware user dashboard that keeps customers engaged, rewards loyalty with a built in points program, and gives sellers a vendor dashboard to manage their business without ever touching WordPress admin.

= Reward Points System =

The built in loyalty points system works across all three roles from one unified portal.

**Customers** earn points automatically on every completed order at a configurable rate. When ready, they convert points into WooCommerce coupon codes with a single click. The coupon is generated instantly, locked to their email, and valid for the number of days you configure. A live progress bar shows exactly how close they are to their next redemption threshold.

**Shop Managers** earn points based on product sales volume — every unit sold adds to their balance. When they accumulate enough they submit a cash withdrawal request specifying their preferred payment method (bank transfer, M-Pesa, PayPal, or other). Requests are held pending administrator approval and the full transaction history is visible in their dashboard.

**Administrators** have a full management console — search and filter all users by role, adjust points individually or in bulk, view lifetime stats (total issued, total redeemed, total expired), approve or reject seller withdrawal requests, configure all earning and redemption rates, import point adjustments from CSV via drag-and-drop, and manually trigger the points expiry check. The dashboard auto-detects the logged-in user's role and renders the correct interface automatically.

= All Dashboard Sections =

**Overview** — Role-specific metrics, 7-day revenue chart for sellers, quick stats, and recent activity for customers.

**Orders** — Full order tracking with status filters, date range selection, search, detailed order modals, and bulk status updates.

**Product Management** — Add, edit, and manage products without WordPress admin. Automatic WebP conversion at 700x700px, rich text editor, inventory tracking, bulk operations, grid and list view.

**Messaging / Inbox** — Two-panel inbox with product-referenced conversations. Messages carry the product image, name, and price automatically. Real-time notifications and read/unread status.

**Reward Points** — Role aware loyalty program with coupon redemption and seller payouts (full details above).

**Visitor Analytics** — Page views, unique visitors, traffic sources, and referrer breakdown. All data stored locally in your WordPress database.

**Phone Contact Tracking** — Every phone click on your product pages is recorded with summary cards and leaderboards.

**Favourites** — Customers save products to a wishlist. Works for logged-out users with automatic post-login merge.

**Cart** — AJAX-powered instant updates, coupon management, stock checking, and mobile swipe-to-delete.

**Account** — Five tabs: Profile, Security, Addresses, Preferences, and Privacy.

= User Roles =

Portal Cloud 9 automatically detects the logged-in user's role and shows the correct dashboard.

* **Administrators** — Full access to all sections including Visitor Analytics, Reward Points admin console, and withdrawal approvals.
* **Shop Managers** — Scoped dashboard showing their own products, orders, phone contacts, inbox, and seller Reward Points view.
* **Customers / Subscribers** — Cart, orders, favourites, messaging, account, and customer Reward Points view.

= No License Required =

All features are fully functional on the free plugin. No license key, no upsell screens, no feature locks, no external connections.

= Works With Portal Cloud 9 Pro =

Portal Cloud 9 also serves as the foundation for Portal Cloud 9 Pro. When Pro is installed, this plugin stays required because it owns the database lifecycle for the whole ecosystem, including the full data wipe in Settings under Data Management that removes every table and option created by both plugins.

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher

== Installation ==

= Automatic Installation (Recommended) =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for **Portal Cloud 9**
3. Click **Install Now** then **Activate**
4. Your WooCommerce customer dashboard is live at yoursite.com/user-portal/

= Manual Installation =

1. Download portal-cloud-9.zip from the WordPress Plugin Directory
2. Go to **Plugins > Add New > Upload Plugin**
3. Choose the ZIP file, click **Install Now**, then **Activate Plugin**

= Post-Activation =

1. Confirm WooCommerce is installed and active
2. Visit **Portal Cloud 9 > Getting Started** for the full setup guide including the Rewards configuration walkthrough
3. Access your dashboard at yoursite.com/user-portal/
4. Configure options at **Portal Cloud 9 > Settings**

= Shortcodes =

**Product Inquiry Button**
[portalcloud9_product_inquiry]

**Seller Phone Display**
[portalcloud9_seller_phone]

**Favourite Button**
[portalcloud9_favourite_button]

**Favourites Counter**
[portalcloud9_favourites_count]

Full documentation: https://gradyzer.com/docs

== External Services ==

Portal Cloud 9 makes no external connections for any core functionality. All features run entirely on your own server. No data is ever sent to Gradyzer or any third party during normal plugin operation.

== Frequently Asked Questions ==

= Can I replace the WooCommerce My Account page? =

Yes. Portal Cloud 9 is built as a My Account replacement. Activate it and every user role gets a role aware client portal at yoursite.com/user-portal/ instead of the default account screen.

= Can I use it as a client portal or membership area? =

Yes. Each role sees its own dashboard, so it works as a customer portal, a vendor dashboard for sellers, and a management console for administrators, all from one plugin.

= Where is Visitor Analytics data stored? =

Entirely in your own WordPress database. Nothing is sent to any external service, which keeps the analytics privacy friendly and under your control.

= Where is the customer dashboard? =

Automatically created at yoursite.com/user-portal/ on activation. If it returns a 404, go to Settings > Permalinks and click Save Changes.

= Does Portal Cloud 9 require WooCommerce? =

Yes. The plugin is built specifically for WooCommerce and will deactivate itself without it.

= How do I assign user roles? =

Go to WordPress Admin > Users, click a user to edit, change the Role dropdown to Shop Manager, Customer, or Administrator, and save. The dashboard immediately shows that user's role-specific interface.

= How does the Reward Points system work? =

Points are awarded automatically when an order status changes to Completed. Customers redeem points for WooCommerce coupons. Shop Managers earn points per item sold and request cash payouts. Admins manage all balances and withdrawals from the Rewards console. All rates are configurable in Rewards > Settings.

= Can I import point balances from CSV? =

Yes. Go to Rewards > Settings > Bulk Import via CSV and upload a file with columns: email_or_username, points, action (add/deduct/set), note.

= Is a license required? =

No. All features are fully functional with no license required.

= Does it work with my theme? =

Yes. Portal Cloud 9 uses completely independent styling.

= Where can I get support? =

Documentation: https://gradyzer.com/docs
Support: https://gradyzer.com/support
Email: support@gradyzer.com

== Screenshots ==

1. WooCommerce customer dashboard overview with stats and revenue chart
2. Reward Points admin console with lifetime stats, user grid, and withdrawal management
3. Reward Points shop manager view with balance, withdrawal form, and top products
4. Reward Points customer view with progress bar and coupon redemption
5. Visitor Analytics with page view charts and traffic source breakdown
6. Product management with grid/list view and WebP conversion
7. Messaging inbox with product-referenced conversations
8. Order management with filters, search, and detailed modals
9. Account management with profile, security, and address tabs
10. Mobile-responsive layout across all sections
11. Settings panel with grouped cards and pill-shaped inputs

== Changelog ==

= 8.7.1 - 2026-07-22 =
* The data wipe in Settings under Data Management now covers the entire Portal Cloud 9 ecosystem. It drops every database table, option, scheduled task, role, and uploaded file created by Portal Cloud 9 Pro as well as the free plugin, so reactivating Pro afterwards rebuilds a completely fresh database
* Users holding plugin created roles are safely moved to the Subscriber role before those roles are removed
* The wipe now refuses to run while Portal Cloud 9 Pro is still active and asks you to deactivate it first
* Removed the recurring admin notice shown when Portal Cloud 9 Pro is active. That notice is owned by Pro, which shows it once after activation and never again
* Refreshed the plugin listing description, tags, and FAQ

= 8.7.0 - 2026-04-07 =
* New: Complete Reward Points system with role-specific dashboards for all three roles
* New: Customers earn points on completed orders and convert to WooCommerce coupons
* New: Shop Managers earn points per item sold and request cash withdrawals
* New: Administrator Rewards console with search, bulk adjustment, and withdrawal approvals
* New: Bulk points adjustment with floating action bar and per-user live balance updates
* New: CSV drag-and-drop import for bulk point adjustments (add/deduct/set)
* New: Lifetime stats bar — Total Issued, Total Redeemed, Total Expired, Last Expiry Run
* New: Daily WP-Cron for automatic points expiry based on inactivity
* New: Manual expiry trigger in Settings with last-run timestamp
* New: Email notifications for points earned, withdrawal approved/rejected, and points expiry
* New: Order links in transaction history log
* New: Customer live progress bar showing points needed to next threshold
* New: Live balance count-up animation after redemption without page reload
* New: Analytics redesigned as two side-by-side cards
* New: Settings panel with grouped cards and hint text per field
* New: Coupon expiry days configurable from Settings (default 7 days)
* Fixed: Coupon creation network error from deprecated set_expiry_date() WC notices
* Fixed: Balance stat cell now updates via AJAX alongside top-right balance number
* Fixed: Toast notifications now show white text on green (success) and red (error)
* Fixed: Settings inputs now correctly pill-shaped overriding dashboard CSS specificity
* Fixed: Manager customer query no longer fails on non-HPOS WooCommerce installs
* Fixed: All 12 reward AJAX actions return JSON error for logged-out users instead of bare -1
* Fixed: Rewards AJAX handlers registered in dedicated loader — eliminates silent 0 responses

= 8.6.1 - 2026-03-15 =
* Fixed: Removed bundled banner and icon images — served from SVN assets directory

= 8.6.0 - 2026-02-17 =
* New: Visitor Analytics tab with page view and unique visitor tracking
* New: Traffic source and referrer breakdown
* New: Date range filter and period selector
* New: Visitor presence heartbeat
* New: Product import and export (CSV and Excel)
* Improved: Tested up to WordPress 6.7

= 8.3.6 - 2026-01-17 =
* Fixed: All JS and CSS files fully unminified
* Fixed: All nonce verifications with wp_unslash()
* Fixed: All output escaped with esc_url(), esc_html(), esc_attr(), wp_kses_post()

= 8.2.9 - 2025-12-10 =
* Security: Nonce verification on all AJAX handlers
* Security: SQL injection fix in order filtering

= 8.2.0 - 2025-10-15 =
* New: Messaging system with inbox and product inquiry
* New: Phone contact tracking

= 8.0.0 - 2025-08-01 =
* Major: Glassmorphic redesign with dark and light mode
* New: Favourites system and enhanced cart

== Upgrade Notice ==

= 8.7.0 =
Major update adding a complete Reward Points system across all user roles, bulk management, CSV import, email notifications, and automatic points expiry. After upgrading visit Portal Cloud 9 > Getting Started to review the Rewards setup guide.

= 8.2.9 =
Important security update. Update immediately.

== License ==

Portal Cloud 9 — Customer Dashboard & Rewards for WooCommerce
Copyright (C) 2025-2026 Gradyzer (Brian Agoi)

This plugin is free software licensed under the GNU General Public License version 2 or later.
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Font Awesome Free 6 is bundled in assets/font-awesome/ under the Font Awesome Free License
(Icons: CC BY 4.0, Fonts: SIL OFL 1.1, Code: MIT). See assets/font-awesome/LICENSE.txt.
