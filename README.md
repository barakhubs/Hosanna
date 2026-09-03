#### README.md

# Hosaana Point of Sale (POS) System

Welcome to the Hosaana Point of Sale (POS) System! This is a comprehensive application built with modern web technologies to streamline sales and inventory management.

**Username:** `admin`
**Password:** `infomax12345`

## Table of Contents
- [Requirements](#requirements)
- [Installation](#installation)
- [Modules](#modules)
- [Features](#features)
- [Attribution](#attribution)
- [License](#license)

## Requirements

To run this application, ensure you have the following installed:

- **PHP**: 8.2 or higher
- **Laravel**: 11
- **Node.js**: (for Inertia JS and React)
- **MySQL**: (for the database)

## Installation

To get started with the POS system, follow these steps:

1. **Clone the repository**:
   ```bash
   git clone https://github.com/NifrasUsanar/InfoShop.git
   cd InfoShop
   ```

2. **Install PHP dependencies**:
   ```bash
   composer install
   ```

3. **Install Node.js dependencies**:
   ```bash
   npm install
   ```

4. **Set up your environment file**:
   Copy the `.env.example` to `.env` and configure your database and other settings.
<br>
5. **Generate the application key**:
   ```bash
   php artisan key:generate
   ```

6. **Create the Database**:

   Set up a new database in MySQL and update the database details in the `.env` file with the following fields:

   Make sure to replace the values (e.g., db_infoshop, root, and the password) with your actual database name, username, and password. If your MySQL server does not require a password, leave the DB_PASSWORD field empty as shown.

   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=db_infoshop
   DB_USERNAME=root
   DB_PASSWORD=
   ```

7. **Run migrations**:
   ```bash
   php artisan migrate --seed
   ```

8. **Link Storage**:
   ```bash
   php artisan storage:link
   ```

9. **Copy template files**:
   Make sure to place the template files in the `storage/app/public/templates` folder by copying them from `resources/views/templates`.
<br>

10. **Start the application**:
   ```bash
   php artisan serve
   ```

11. **Compile assets**:
   ```bash
   npm run dev
   ```

## Modules

The POS system includes the following modules:

- **POS**: Manage point-of-sale transactions seamlessly.
- **Sales**: Track and manage sales data.
- **Products**: Handle batch products efficiently.
- **Purchases**: Record and manage purchase orders.
- **Payments**: Process various payment types.
- **Expenses**: Keep track of business expenses.
- **Contacts**: Manage customer and vendor information.
  - **Contact Balances**: Easily manage and view balances for contacts.

## Features

- Built with **Laravel** for robust server-side functionality.
- Utilizes **Inertia JS** and **React JS** for a modern, reactive user interface.
- **MUI** (Material-UI) as the components library for beautiful, responsive design.
- Styled with **Tailwind CSS** for a clean and customizable look.
- MySQL as the database for reliable data storage.

## Attribution

If you use this project or any of its contents, please provide proper attribution. You can mention:

"This project is based on Infoshop by Infomax / Nifras Usanar."

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for more details.

---


Feel free to contribute to this project or reach out with any questions. Happy coding! 🚀
