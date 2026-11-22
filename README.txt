RESERVEHUB - School Facility Reservation and Equipment Booking Management System
=================================================================================

INSTALL (fresh)
1. Install XAMPP, start Apache and MySQL.
2. Copy this folder to C:\xampp\htdocs\school_reservation_simple
3. phpMyAdmin > Import > database.sql
4. Open http://localhost/school_reservation_simple/login.php

ALREADY IMPORTED AN OLD VERSION?
   Nothing to do. database.sql is safe to import again, and the app adds any missing
   columns/tables by itself (including notifications). Your data is kept.
   If the import button fails: open phpMyAdmin > SQL tab, paste the contents of
   database.sql and click Go.

Demo accounts (created automatically on first login page load):
   admin / admin123    assistant1 / assist123    assistant2 / assist123
   student1 / student123  faculty1 / faculty123

LATEST CHANGES
A. Equipment stock deducts when reserved. The Equipment page, dashboard, reserve form and
   Manage equipment now show "available of total". Units held by Pending / Approved / In Use
   reservations that have not ended are deducted; Cancelled, Rejected, Completed or finished
   reservations give the units back automatically.
B. Two administrator levels
   Admin            everything: approvals, facilities, equipment, reports, and all accounts
   Assistant Admin  reservations only (approve, reject, mark in use / completed)
   There is one Admin and two Assistant Admin accounts. The old Head Admin was removed.

C. Cancel asks "Are you sure?" in a pop-up. A student can edit a reservation until it starts
   (while Pending or Approved). Editing an Approved one sends it back to Pending.

WHAT'S NEW (your 6 requests)
1. Forgot password   forgot_password.php + reset_password.php. One-time link, 30 min.
                     XAMPP has no mail server, so links are also written to logs/outbox.log
                     and (demo mode) shown on screen. Set SHOW_RESET_LINK_ON_SCREEN=false in
                     config.php before real use. Admin also sees reset requests in Users.
2. Same-time warning While filling the form, the page checks live and warns:
                     red  = already reserved (approved / in use)
                     amber = another user requested it first and is waiting for approval
                     The day's taken time slots are listed beside the form.
3. Validity          Each reservation shows Valid / Awaiting approval / Not valid, taken from the
                     database. Search a reference no. under All reservations to confirm it.
4. Admin accounts    users.php: admin creates (accounts are deactivated, never deleted) the account, system makes a temporary password
                     (shown once). First login forces the user to set their own password;
                     they can change it again anytime in Profile & password.
5. No double booking Same facility + overlapping time = blocked (also re-checked inside a
                     database transaction, so two clicks at the same second can't both win).
                     Equipment is quantity-based: blocked when requested units exceed free units
                     (e.g. 5 projectors can serve 5 different bookings at the same time).
                     Same user + same equipment + overlapping time = duplicate, blocked.
6. Minimalist UI     White cards, black buttons, sidebar layout, pictures on every facility and
                     equipment card, split-screen login. Lists use Back / 1 2 3 ... / Next.

PICTURES
   Built-in illustrations show automatically. Admin > Approvals & resources > Facilities /
   Equipment > Edit lets you upload your own JPG/PNG/WEBP (max 3 MB) for any item.

FILES
   login, forgot_password, reset_password, logout   - access
   dashboard, facilities, equipment                 - browse
   reserve, check_availability, reservations        - booking + conflict checks
   admin (approvals, facilities, equipment), users, reports (5 reports + CSV + print)
   config.php (settings), functions.php, art.php, layout.php, style.css, app.js
   uploads/ must be writable. logs/ and uploads/ include .htaccess protection.

Needs PHP 7.4+ (XAMPP 8.x is fine) with mysqli, fileinfo and mbstring (all on by default).

LATEST (conflicts + users)
   Conflicts: the same time is invalid as soon as it is requested (Pending, Approved or In Use).
   Users: Edit (includes Active/Inactive and Change password) and Delete (asks "Are you sure?").
   Admin accounts: admin, assistant1, assistant2 only. The old Head Admin / 'assistant' accounts are removed automatically.

HOW A RESERVATION MOVES (admin approves)
   Student submits  ->  Pending
   Admin decides    ->  Approved  or  Declined      (admin.php > Reservations, Approve / Decline / Edit)
   Start time comes ->  In Use      (automatic, nothing to click)
   End time passes  ->  Completed   (automatic, nothing to click)
   Admin can set Pending / Approved / Declined / Cancelled at any time (Edit button).
   ONLY In Use and Completed are automatic. Approving, declining and cancelling are always the
   administrator's decision (a Pending request whose time passed stays in the list until the admin
   declines or cancels it; it can no longer be approved).
   Checked every time any page loads (see the end of db.php).

AVAILABILITY (what the Available / Unavailable labels mean)
   Equipment   "3 of 5 available now" = units NOT In Use right now. A booking for next week does not
               reduce today's stock; it is listed under the item as "Reserved <date> <time>".
               Units come back when a reservation turns Completed, or is Declined / Cancelled.
               Choosing a date and time on the reserve form shows the free units for THAT time.
   Facilities  Available, In Use (someone is using it right now) or Unavailable (admin switched it off).
               Future bookings are listed under it as "Reserved <date> <time>".
   Time slots  Pending, Approved and In Use requests all hold their time, so nobody else can book
               the same facility/equipment time (amber = requested, waiting for approval).
