# ProfitPocket Project Instructions

## Project Overview

ProfitPocket is a financial management web application
for micro-entrepreneurs in the Philippines.

## Technology Stack

- PHP 8+
- MySQL 8
- HTML5
- CSS3
- JavaScript
- Bootstrap 5
- XAMPP

## Existing Database

Database name: profitpocket_db

The MySQL database already exists.

IMPORTANT:

- Inspect the existing SQL schema before writing queries.
- Use the actual database tables and column names.
- Do not recreate, drop, or reset the database.
- Do not modify its structure without my approval.
- Do not use fake data in completed backend features.

## Folder Structure

- config/ - Database connection
- assets/ - CSS, JS, images
- includes/ - Reusable components
- auth/ - Authentication
- user/ - Business owner modules
- admin/ - System administrator modules
- uploads/ - Receipt storage
- database/ - SQL reference files

## Core Features

1. Registration and login
2. Business dashboard
3. Sales management
4. Expense management
5. Customer management
6. Utang and partial payments
7. Profit calculation and reports
8. System administrator panel

## Coding Rules

- Keep code simple, clean, and beginner-friendly.
- Follow the existing folder structure.
- Use reusable PHP includes.
- Use Bootstrap for responsive layouts.
- Use PDO or MySQLi prepared statements.
- Use PHP sessions for authentication.
- Enforce owner/admin authorization.
- Validate input and escape HTML output.
- Use password_hash() and password_verify().
- Use database transactions for financial operations.
- Prevent cross-business data access.
- Never expose database credentials.
- Do not add unnecessary libraries or frameworks.
- Do not implement features outside the project scope.

## Development Workflow

- Implement one module at a time.
- Reuse existing components.
- Never overwrite working code unnecessarily.
- Keep the interface consistent across all pages.
- Check PHP syntax after modifications.
- Test calculations and database operations.
- Explain which files were changed.
- Report anything that could not be tested.

## Design Style

- Clean, modern financial dashboard.
- Forest green and white color palette.
- Mobile responsive.
- Simple navigation and readable tables.
- Consistent buttons, forms, and alerts.
