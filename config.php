<?php
// ---- Basic settings -------------------------------------------------
date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'Facilities Reservation');
define('SCHOOL_NAME', 'Computer Arts and Technological College, Inc.');

// Secret key for the app. Change to any long random text.
define('APP_SECRET', 'change-this-to-a-long-random-string-before-going-live');

// Forgot-password: XAMPP has no mail server by default.
// true  = also show the reset link on screen (demo/testing only!)
// false = only email it (and write it to logs/outbox.log)
define('SHOW_RESET_LINK_ON_SCREEN', true);
define('MAIL_FROM', 'no-reply@school.local');

// ---- REAL EMAIL (SMTP) ----------------------------------------------
// Fill these in so reset links really arrive in the user's inbox.
// Gmail: turn on 2-Step Verification, then create an "App password"
//        (Google Account > Security > App passwords) and paste it below.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);            // 587 = 'tls'  |  465 = 'ssl'
define('SMTP_SECURE', 'tls');
define('SMTP_USER', '');             // e.g. yourschool@gmail.com  (empty = SMTP off)
define('SMTP_PASS', '');             // the 16-letter app password
define('RESET_TOKEN_MINUTES', 30);

// Reservations are allowed only inside these hours
define('OPEN_TIME', '07:00');
define('CLOSE_TIME', '21:00');

// (Not used any more) A reservation can be edited only while it is Pending; once approved it is locked.
define('EDIT_LOCK_HOURS', 24);

// Rows per page in lists (Back / 1 2 3 / Next)
define('PER_PAGE', 8);

// Statuses that "hold" a time slot
const ACTIVE_STATUSES = ['Pending', 'Approved', 'In Use'];
// A time slot is taken as soon as it is requested (Pending), not only once approved.
const CONFLICT_STATUSES = ['Pending', 'Approved', 'In Use'];
