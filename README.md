# Gathero Event Booking

Gathero is a PHP and MySQL event booking platform for managing event discovery, registration, and organizer workflows. The application supports two main account roles: `attendee` and `organizer`, with additional admin-only controls for selected organizers.

## Features

- Role-based registration and login with automatic routing to the correct dashboard
- Attendee event browsing, booking, cancellation, attendance check-in, and feedback submission
- Organizer event creation, event oversight, attendee export, and profile management
- Organizer collaboration requests for shared event management
- Event cancellation workflow with approval handling for collaborative events
- Admin-only user management and cancellation audit visibility
- Profile customization, including bio, social links, password updates, and profile picture uploads

## Local Setup

### Requirements

- XAMPP with Apache and MySQL enabled
- PHP with PDO MySQL support
- A browser for accessing the local app

### Run Locally

1. Place the project folder inside `xampp/htdocs`.
2. Start `Apache` and `MySQL` from the XAMPP Control Panel.
3. Create a database named `eventbooking` in phpMyAdmin.
4. Import [`eventbooking.sql`](./eventbooking.sql) into that database.
5. Configure database access using either environment variables or a local override file:
   - Copy [`db.local.example.php`](./db.local.example.php) to `db.local.php`
   - Update the database host, name, username, and password for your local environment
6. Open the application in your browser:

```text
http://localhost/Event_Booking/
```

The root entry point is [`index.php`](./index.php), which loads the landing page from [`index.html`](./index.html).

## Configuration

Database configuration is resolved in [`db.php`](./db.php) in this order:

1. Environment variables:
   - `DB_HOST`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
2. Local override file: `db.local.php`
3. Built-in defaults:
   - host: `localhost`
   - database: `eventbooking`
   - user: `root`
   - password: empty string

The repository includes [`db.local.example.php`](./db.local.example.php) as a template for local-only credentials. Keep `db.local.php` environment-specific.

Sessions are initialized through [`session_bootstrap.php`](./session_bootstrap.php), which applies secure cookie settings and starts PHP sessions when needed.

## Project Structure

### Pages and Entry Points

- [`index.html`](./index.html): landing page
- [`events.html`](./events.html): public event discovery and booking UI
- [`register.php`](./register.php) and [`login.php`](./login.php): authentication flows
- [`attendee_profile.php`](./attendee_profile.php): attendee dashboard and profile
- [`organizer_profile.php`](./organizer_profile.php): organizer dashboard, collaboration, notifications, and cancellation workflows
- [`manage_users.php`](./manage_users.php): admin-only user management and cancellation audit page
- [`my_bookings.html`](./my_bookings.html): attendee booking history, check-in, cancellation, and feedback actions
- [`create_event.html`](./create_event.html): event creation interface
- [`feedback.html`](./feedback.html): attendee feedback form

### Backend Endpoints

- [`get_events.php`](./get_events.php): returns event data for the event listing
- [`book_event.php`](./book_event.php): creates attendee bookings
- [`cancel_booking.php`](./cancel_booking.php): cancels attendee bookings
- [`get_bookings.php`](./get_bookings.php): returns booking and attendance data
- [`update_attendance.php`](./update_attendance.php): updates attendee check-in status
- [`feedback.php`](./feedback.php): reads and submits event feedback
- [`get_session.php`](./get_session.php): returns current session state for front-end role-aware behavior
- [`create_event.php`](./create_event.php): saves new events and organizer collaboration assignments
- [`export_event_attendees.php`](./export_event_attendees.php): exports attendee lists for organizer-owned events
- [`upload_profile_pic.php`](./upload_profile_pic.php): handles profile image uploads

### Data and Assets

- [`eventbooking.sql`](./eventbooking.sql): primary local database schema and sample data dump
- [`eventbooking.infinityfree.sql`](./eventbooking.infinityfree.sql): alternate SQL dump for related hosted deployment workflows
- [`uploads/`](./uploads): uploaded profile pictures and brand assets
- [`global_nav.js`](./global_nav.js): shared navigation behavior based on session state

## Database Overview

The main schema in [`eventbooking.sql`](./eventbooking.sql) includes these core areas:

- `users`: shared account records and role assignment
- `organizer` and `attendee`: role-specific identity tables
- `user_profile`: profile metadata such as bio, social links, and profile picture
- `eventdetails`: event records, dates, locations, and cancellation metadata
- `create_event`: links organizers to created events
- `booking`: attendee reservations, cancellation state, and attendance status
- `feedback`: attendee event ratings and comments
- `event_collaboration_requests`: organizer collaboration invitations and responses
- `event_cancellation_batches` and `event_cancellation_approvals`: collaborative cancellation approval workflow
- `notifications`: organizer-facing activity and request notifications

## Suggested Local Test Flow

Use this sequence to verify the main user journeys after setup:

1. Register one `organizer` account and one `attendee` account.
2. Log in as the organizer and create a new event from [`create_event.html`](./create_event.html).
3. Log in as the attendee, browse [`events.html`](./events.html), and book that event.
4. Open [`my_bookings.html`](./my_bookings.html) as the attendee to:
   - review the booking
   - cancel an upcoming booking
   - check in on the event date
   - leave feedback after attendance or after the event date has passed
5. Create a second organizer account to test collaboration requests and shared event cancellation approval flows from [`organizer_profile.php`](./organizer_profile.php).
6. If your organizer account is marked as admin in the database, open [`manage_users.php`](./manage_users.php) to test user administration and cancellation audit visibility.

## Notes

- The app uses fetch-based front-end interactions with PHP endpoints rather than a separate API server.
- Uploaded files are stored in the local `uploads` directory, so make sure Apache can write to it in your environment.
- No demo credentials are included in this repository. Create local accounts through the registration flow.

## License

This project is licensed under the Apache License 2.0. See [`LICENSE`](./LICENSE) for the full text.
