<<<<<<< HEAD
# Tharimpepe Feeding Scheme Management System — User Guide

This guide covers the website and Android app for staff and volunteers managing the feeding scheme. Screens and available actions depend on the signed-in user's role.

## Getting started

1. Open the website or launch the Android app.
2. Sign in with an account provided by the system administrator. New registrations may need administrator approval before sign-in.
3. Use the dashboard to review current activity. Use the navigation menu to open the modules available to your account.
4. Sign out when you finish, especially on a shared device.

If sign-in fails, check the username and password, confirm that the account is active, and contact the administrator. Do not share passwords.

## Main modules

### Beneficiaries

Use this module to view and register people who receive meals. Enter accurate details, then save the record. Use the list's search and filters to find a person. Update a record when details or status change. Only collect personal information needed by the feeding scheme.

### Attendance

Choose the meal date/session, mark each beneficiary's attendance, and save the record. Review the daily summary or attendance history to correct mistakes and confirm totals. Avoid recording attendance twice for the same person and session.

### Food stock

Add inventory items with their name, quantity, unit, and relevant dates. Update quantities when stock is received or distributed. Check low-stock and expiry information before planning meals.

### Donations

Record the donor, donation type, date, and amount or item details. Review the donation list and receipt/report screens to confirm the entry. Keep descriptions factual and do not store payment card details.

### Volunteers

Register or update volunteer details and availability. Where schedules are available, review the assigned date, time, and location and update the shift status after service.

### Reports

Choose the report that matches the question, review its date range and filters, then print or export when those actions are available. Treat exports as confidential because they may contain personal or operational information.

### Profile and account access

Use profile/account options to review your details or change your password if the screen provides that action. Ask an administrator to resolve account status or role problems.

## Android app notes

The Android app uses the same system account and server data as the website. The app must be able to reach the configured API server. If lists fail to load, first check the device's network connection and ask the administrator whether the service is running and the device is on the supported network.

## Common problems

| Problem | What to try |
|---|---|
| Account is pending or inactive | Ask an administrator to review the account. |
| A page or action is missing | Your role may not have access; ask the administrator to confirm your permissions. |
| Data does not load in Android | Check connectivity and contact the administrator about server availability. |
| A saved record looks wrong | Reopen it and correct it if permitted; otherwise report the record, screen, and date to an administrator. |
| You find a software problem | Record the screen, steps to reproduce, expected result, actual result, and (if safe) a screenshot. Do not include passwords or sensitive beneficiary data in issue reports. |

## Support and privacy

For access, data correction, or technical support, contact the feeding scheme administrator through the channel your organization has provided. Share the minimum information needed to describe the problem. Never send passwords, authentication tokens, or unnecessary beneficiary details.

> This is an initial guide based on the repository's documented modules. Before submission, the team should walk through each instruction on the current website and Android build, add current screenshots, and correct any screen labels or steps that differ.
=======
# Tharimpepe Feeding Scheme Management System (FSMS)
## User Guide & Documentation

Welcome to the **Tharimpepe Feeding Scheme Management System (FSMS)** user manual. This guide provides comprehensive instructions for administrators, staff, volunteers, and donors navigating both the Web Application and the Android Mobile Application.

---

## 📖 Table of Contents
1. [Accessing In-App Help](#1-accessing-in-app-help)
2. [Sign-In & Access Control](#2-sign-in--access-control)
3. [Dashboard & Operations Overview](#3-dashboard--operations-overview)
4. [Beneficiary Management](#4-beneficiary-management)
5. [Attendance Tracking](#5-attendance-tracking)
6. [Food Stock & Inventory Management](#6-food-stock--inventory-management)
7. [Donations & Donor Management](#7-donations--donor-management)
8. [Volunteer Management](#8-volunteer-management)
9. [Reports & Data Export](#9-reports--data-export)
10. [User Management & Profile Settings](#10-user-management--profile-settings)
11. [Support & Technical Assistance](#11-support--technical-assistance)

---

## 1. Accessing In-App Help

To view this guide or obtain help directly within the system:
* **Web Portal:** Click the **Help** entry located in the primary navigation header/sidebar or in the user profile menu at the top right of the screen.
* **Android Application:** Open the navigation drawer (hamburger menu icon `≡`) at the top left of the application screen or tap the **More** (`...`) tab in the bottom navigation bar and select **Help & User Guide**.

---

## 2. Sign-In & Access Control

### 2.1 Logging In
1. Open the application on your web browser or Android mobile device.
2. Enter your assigned **Username** and **Password**.
3. Tap or click **Login**.
4. If you do not have an account, click **Create one** (subject to administrator approval) or request account creation from your system administrator.

### 2.2 Guest Access
* Guests and anonymous contributors can tap **Quick Donate as Guest** on the login page to submit financial support without logging into the portal.

### 2.3 Roles & Permissions Overview
The system implements Role-Based Access Control (RBAC) to ensure security and privacy:
* **Admin:** Full system access including user creation, system configuration, reporting, stock, attendance, and record deletions.
* **Staff:** Full operational access to beneficiaries, attendance, food stock, donations, and standard reports.
* **Volunteer:** Operational access to record attendance, view beneficiary details, log incoming donations, and manage stock items.
* **Donor:** View-only access to donation history, tax receipts, and general scheme impact reports.

---

## 3. Dashboard & Operations Overview

Upon successful login, the **Dashboard** presents high-level operational statistics:
* **Active Beneficiaries:** Total registered beneficiaries actively receiving meals.
* **Today's Attendance:** Live count of beneficiaries served today versus weekly metrics.
* **Available Volunteers:** Count of active volunteers available on shift.
* **Items in Stock:** Total distinct inventory items available and low-stock alerts.
* **Monthly Donations:** Total funds and contributions collected during the current calendar month.
* **Today's Shifts:** Scheduled and upcoming staff/volunteer work shifts.

---

## 4. Beneficiary Management

### 4.1 Viewing Beneficiaries
1. Navigate to **Beneficiaries** using the main menu or bottom navigation.
2. Select the **List** tab.
3. Use the **Search...** box to filter beneficiaries by name.
4. Click **View** next to any record to view details (Age, Gender, Status, Contact Info).

### 4.2 Registering a New Beneficiary
1. Select the **Register** tab under the **Beneficiaries** section.
2. Fill in the required details:
   * **First Name** and **Last Name** *(Required)*
   * **Age** and **Gender**
   * **Phone Number** and **Email Address**
   * **Residential Address**
   * **Registration Date** *(Required)*
3. Tap **Register Beneficiary** to submit and save the record.

---

## 5. Attendance Tracking

The **Attendance** module is designed for fast, real-time daily tracking during feeding sessions.

### 5.1 Marking Attendance
1. Navigate to **Attendance** and select the **Mark** tab.
2. Select or confirm the target date (defaults to today's date). Tap **Load**.
3. Tap individual beneficiary tiles to cycle through statuses:
   * **Present** (Green highlight)
   * **Absent** (Grey highlight)
   * **Marked**
4. Review the tiles and tap **Save Attendance** at the bottom to finalize.

### 5.2 Viewing Attendance History
* Switch to the **History** tab to inspect past attendance logs, search specific dates, or review daily attendance counts.

---

## 6. Food Stock & Inventory Management

### 6.1 Checking Current Stock
1. Go to **Food Stock** and tap the **Inventory** tab.
2. Review items listed with their **Quantity (QTY)**, **Unit** (e.g., `kg`, `litres`, `units`), and **Status** (`ok`, `low stock`, `expired`).

### 6.2 Adding Stock Items
1. Tap the **Add Item** tab.
2. Enter the following details:
   * **Item Name** *(Required - e.g., Maize meal, Rice, Sugar)*
   * **Quantity** *(Required - numerical value)*
   * **Unit** *(Required - e.g., kg, g, l)*
   * **Expiry Date** *(Optional)*
   * **Notes** *(Optional)*
3. Tap **Add Item** to update system inventory.

---

## 7. Donations & Donor Management

### 7.1 Recording a Donation
1. Navigate to **Donations**.
2. Choose account options:
   * Tap **Create Donor Account** to register a recurring donor.
   * Check **Donate anonymously** for one-off/anonymous contributors.
3. Enter donation parameters:
   * **Donor Name** & **Donor Email**
   * **Donation Type** *(e.g., Cash, In-Kind Food, Equipment)*
   * **Amount** *(for monetary contributions)*
   * **Description** *(optional details regarding food items or notes)*
   * **Date**
4. Tap **Record Donation**.

### 7.2 Viewing Recent Donations
* Scroll down to the **Recent Donations** list to review recent transaction logs, dates, and amounts.

---

## 8. Volunteer Management

### 8.1 Searching Volunteers
1. Navigate to **Volunteers** and select the **List** tab.
2. Search by name, phone number, email address, or status using the search bar.
3. Tap **View** to inspect a volunteer profile.

### 8.2 Registering a New Volunteer
1. Select the **Register** tab under **Volunteers**.
2. Enter the user details:
   * **User ID** *(System/User reference)*
   * **First Name** & **Last Name**
   * **Phone Number** & **Address**
3. Tap **Register Volunteer**.

---

## 9. Reports & Data Export

### 9.1 Generating Reports
1. Go to **Reports**.
2. Select a **Report Type** from the dropdown menu:
   * *Beneficiary Report*
   * *Attendance Summary Report*
   * *Stock & Inventory Report*
   * *Donations & Financial Summary*
3. Set the date range (**From** date and **To** date).
4. Tap **Generate Report**.

### 9.2 Exporting & Printing
Once generated, you can export data using the controls at the top of the summary table:
* **Print:** Sends formatted report to connected mobile/desktop printers or saves as a PDF.
* **Export CSV:** Downloads a comma-separated values file for spreadsheet analysis.
* **Export XLS:** Downloads an Excel-compatible spreadsheet document.

---

## 10. User Management & Profile Settings

### 10.1 User Management (Administrators Only)
1. Go to **Users** via the side drawer menu (`≡`).
2. View existing users, their roles (`admin`, `staff`, `volunteer`, `donor`), and their system status (`active` / `inactive`).
3. Tap **Add User** to create new system user credentials.
4. Tap **Edit** to modify roles or **Deactivate** to remove user access.

### 10.2 Profile & Password Management
1. Navigate to **My Profile** via the menu.
2. **Account Information:** View your registered full name, email, phone number, and account creation timestamp.
3. **Edit Profile:** Update your displayed Full Name, Email, or Contact Number and tap **Save Profile**.
4. **Change Password:** Enter your **Current Password**, followed by your **New Password** and **Confirm New Password**, then tap **Change Password**.

---

## 11. Support & Technical Assistance

### 11.1 Reporting an Issue
If you encounter a bug, system error, or need technical help while using the website or mobile app, follow these reporting guidelines:

1. Contact the system administrator or IT helpdesk via email or internal ticketing.
2. Provide a clear, detailed description of the problem:
   * What screen or feature were you using?
   * What steps reproduce the problem?
   * What error message (if any) appeared on the screen?

### 🔒 11.2 Data Privacy & Security Instructions
To protect system security and protect sensitive data under privacy regulation:
* **NEVER share your password** or user credentials when submitting a support ticket.
* **Do NOT include unnecessary personal data** (such as beneficiary ID numbers, home addresses, or personal phone numbers) in screenshots or support request bodies.
* Support personnel will **never** ask for your account password.
>>>>>>> b51fca0 (docs: add user guide for website and Android app)
