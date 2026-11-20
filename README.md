# Mannan Restaurant Management System

A restaurant POS and operations system for **Mannan Restaurant, Hyderabad, Sindh, Pakistan**. Counter staff, waiters, kitchen staff, managers and the owner work from one connected platform: orders, tables, kitchen workflow, payments and a live dashboard.

**Author:** Hina Ayoub (Full-Stack Developer)
**Stack:** HTML, CSS, JavaScript (frontend) · PHP (backend API) · MySQL (database) · XAMPP (local server)

---

## Features

**Authentication and accounts**
- Secure login with PHP sessions and hashed passwords
- Forgot Password and Reset Password (one-time link, expires in 30 minutes)
- My Profile: change name and password
- Role-based access, enforced on the server for every request

**POS and orders**
- Menu grid, item quantities, discount, live subtotal and total
- Dine-In, Takeaway and Delivery order types
- Dine-In orders are linked to an available table
- Prices and totals are always recalculated on the server
- Duplicate-submission protection (double clicks cannot create two orders)
- Checkout with Cash, Card, EasyPaisa or JazzCash, plus a printable receipt
- Cancel an order with a recorded reason (Admin and Manager only)

**Tables**
- Floor layout with five states: Available, Occupied, Reserved, Needs Cleaning, Out of Service
- A table becomes Occupied when a Dine-In order is created, and Needs Cleaning after the served order is paid

**Kitchen Display System**
- New orders appear automatically (refreshes every 10 seconds)
- Kitchen marks tickets: Start Preparing → Ready → Served
- The kitchen never sees prices or revenue

**Admin dashboard (Admin and Manager)**
- Daily / 7-day / 30-day filter
- Sales, order count, active kitchen queue, tables occupied
- Orders by type, table status, top-selling items, recent transactions, cashier-wise summary

---

## Roles and permissions

| Role | Can do |
|---|---|
| Admin / Owner | Everything: dashboard, POS, tables, kitchen, orders, payments, cancellations |
| Manager | Same as Admin in this version |
| Cashier | POS, discounts, tables, orders, payments, receipts |
| Waiter | POS (no discounts), tables, view orders; cannot take payments |
| Kitchen | Kitchen display only (no prices or revenue) |

All roles can open **My Profile**.

---

## Sample accounts (testing only)

These are fake sample accounts created by `database.sql`.

| Role | Email | Password |
|---|---|---|
| Admin | admin@mannan.com | admin123 |
| Manager | manager@mannan.com | manager123 |
| Cashier | cashier@mannan.com | cashier123 |
| Waiter | waiter@mannan.com | waiter123 |
| Kitchen | kitchen@mannan.com | kitchen123 |

---

## How to run it locally

1. Install **XAMPP** and start **Apache** and **MySQL**.
2. Put this project folder in `C:\xampp\htdocs\` and name it `mannan-restaurant-pos`.
3. Open `http://localhost/phpmyadmin`, click **Import**, choose `database.sql`, and click **Go**.
   (This creates the `mannan_restaurant` database. If one already exists, it is replaced.)
4. Database settings are in `api/db.php` (default XAMPP: user `root`, no password).
5. Open **http://localhost/mannan-restaurant-pos/** and sign in with a sample account.

### Try the full order flow
1. Sign in as **Cashier**, open POS, add items, choose a table, and click *Confirm & Send to Kitchen*.
2. Sign in as **Kitchen**, then Start Preparing → Mark Ready → Mark Served.
3. Sign in as **Cashier** again, open *Orders & Checkout*, pay, and view the receipt.
4. Sign in as **Admin** to see the sale on the dashboard.

### Try Forgot Password
There is no mail server on XAMPP, so the reset link is saved to `storage/reset_links.log` (created automatically, blocked from web access, and ignored by Git). Open that file, copy the latest link, and open it in the browser. In production, the link would be sent by email.

---

## Project structure

```
mannan-restaurant-pos/
├── api/                    PHP backend
│   ├── db.php              database connection, sessions, helper functions
│   ├── login.php, logout.php
│   ├── forgot_password.php, reset_password.php, profile.php
│   ├── menu.php, tables.php, orders.php, dashboard.php
├── storage/                private files (reset-link log), blocked from the web
├── index.html              login
├── forgot_password.html, reset_password.html
├── app.html                main application (all screens as tabs)
├── app.js                  frontend logic
├── style.css
└── database.sql            schema and sample data
```

---

## Security notes
- Passwords are stored as bcrypt hashes and never shown in the interface
- Every API endpoint checks login and role on the server
- All database queries use prepared statements
- Prices, totals, discounts and payment methods are validated on the server
- Reset tokens are stored only as hashes, work once, and expire
- Forgot Password gives the same reply whether or not the email exists

---

## Assumptions
- Payments are recorded by the cashier. No payment gateway is used (out of scope in the brief).
- Tax and service charges are not applied in this version.
- Dashboard figures are grouped by the order creation date.
- Paid orders cannot be cancelled, because refunds need their own workflow.
- The Manager role currently has the same permissions as Admin.

## Unfinished items (not built in this version)
- Order Details and Order History pages with search and filters
- Table Details and Table Reservations (optional in the brief)
- Menu management (categories, items, add-ons and variations)
- Inventory (stock list, stock in/out, suppliers, low-stock alerts)
- Customers, Staff Management, Roles & Permissions, Settings, Tax / Service Charges
- Audit / Activity Log
- Reports (sales, orders, payments, items, inventory, shifts)
- Split payments, moving or merging tables
