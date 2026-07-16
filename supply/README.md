# Supply Chain Management System

A comprehensive web-based Supply Chain Management System built with PHP and MySQL. This system helps manage procurement, inventory, logistics, suppliers, warehouses, and provides detailed reporting capabilities.

## Features

- **User Authentication & Role-Based Access Control**
  - Admin, Procurement Staff, Warehouse Officer, Logistics Manager roles
  - Secure login system with session management

- **Dashboard**
  - Real-time summary cards showing key metrics
  - Low stock alerts
  - Recent purchase orders
  - Active shipments overview

- **Demand Forecasting & Planning**
  - Generate demand forecasts based on historical sales data
  - View sales trends with charts
  - Adjust production plans based on forecasts

- **Procurement & Supplier Coordination**
  - Create and manage purchase orders
  - Track order status (Pending, Approved, In Transit, Received)
  - Supplier performance tracking
  - Automated reordering capabilities

- **Suppliers Management**
  - Add, edit, and manage supplier information
  - Track supplier performance ratings
  - View supplier order history

- **Logistics & Transportation Management**
  - Schedule inbound shipments
  - Track shipments with tracking numbers
  - Monitor transportation costs
  - Real-time shipment status updates

- **Inventory Management**
  - View inventory across all warehouses
  - Stock in/out movements
  - Low stock alerts
  - Inventory valuation
  - Stock movement history

- **Inventory Distribution & Warehouse Coordination**
  - Manage multiple warehouse locations
  - Create inter-warehouse transfer requests
  - Monitor warehouse capacity
  - Track transfer status

- **Reports Module**
  - Inventory Reports
  - Procurement Reports
  - Supplier Performance Reports
  - Logistics & Delivery Reports
  - Demand Forecast Reports
  - Cost Analysis Reports

- **Users Management (Admin Only)**
  - Add, edit, and manage system users
  - Assign roles and permissions
  - Activate/deactivate user accounts

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache web server (XAMPP recommended)
- Modern web browser

## Installation

1. **Clone or download the project** to your web server directory:
   ```
   C:\xampp\htdocs\supply
   ```

2. **Create the database**:
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Import the `database/schema.sql` file to create the database and tables
   - Or run the SQL file manually in MySQL
   - (Optional) Import `database/sample_data_korean.sql` to add Korean products and sample data

3. **Configure database connection**:
   - Edit `config/database.php` if your MySQL credentials are different:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_USER', 'root');
     define('DB_PASS', '');
     define('DB_NAME', 'supply_chain_db');
     ```

4. **Set up base URL** (if needed):
   - Edit `config/config.php` and update the BASE_URL if your installation path is different:
     ```php
     define('BASE_URL', 'http://localhost/supply/');
     ```

5. **Access the application**:
   - Open your browser and navigate to: `http://localhost/supply/`

## Default Login Credentials

### Admin User
- **Username:** `admin`
- **Password:** `admin123`

### Procurement Staff
- **Username:** `procurement_staff`
- **Password:** `procurement123`

### Warehouse Officer
- **Username:** `warehouse_officer`
- **Password:** `warehouse123`

### Logistics Manager
- **Username:** `logistics_manager`
- **Password:** `logistics123`

### Supplier
- **Username:** `supplier1`
- **Password:** `supplier123`

**Important:** Change the default passwords after first login!

**Note for Supplier Account:** After creating the supplier user, you need to link it to a supplier record in the database:
```sql
UPDATE suppliers SET user_id = (SELECT id FROM users WHERE username = 'supplier1') WHERE id = [supplier_id];
```
Replace `[supplier_id]` with the actual supplier ID you want to link to.

## User Roles

- **Admin:** Full system access including user management
- **Procurement Staff:** Can manage purchase orders and suppliers
- **Warehouse Officer:** Can manage inventory and warehouse transfers
- **Logistics Manager:** Can manage shipments and logistics

## Project Structure

```
supply/
├── assets/
│   ├── css/
│   │   └── style.css          # Main stylesheet
│   └── js/
│       └── main.js            # Main JavaScript file
├── config/
│   ├── config.php             # Application configuration
│   └── database.php           # Database connection
├── database/
│   └── schema.sql             # Database schema
├── includes/
│   ├── header.php             # Page header
│   └── footer.php             # Page footer
├── modules/
│   ├── demand_forecasting.php # Demand forecasting module
│   ├── procurement.php       # Procurement module
│   ├── suppliers.php          # Suppliers management
│   ├── logistics.php          # Logistics module
│   ├── inventory.php          # Inventory management
│   ├── warehouse.php          # Warehouse coordination
│   ├── reports.php            # Reports module
│   └── users.php              # User management (Admin only)
├── dashboard.php              # Dashboard page
├── index.php                  # Login page
├── logout.php                 # Logout handler
└── README.md                  # This file
```

## Usage Guide

### 1. Login
- Access the login page and enter your credentials
- You'll be redirected to the dashboard based on your role

### 2. Dashboard
- View summary statistics and recent activities
- Navigate to different modules using the sidebar

### 3. Creating a Purchase Order
1. Go to **Procurement** module
2. Click "Create Purchase Order"
3. Select supplier, add items, set delivery date
4. Submit for approval

### 4. Managing Inventory
1. Go to **Inventory** module
2. View current stock levels
3. Use "Update Stock" to add/remove inventory
4. Monitor low stock alerts

### 5. Scheduling Shipments
1. Go to **Logistics** module
2. Click "Schedule Shipment"
3. Select shipment details (supplier, destination warehouse)
4. Add items and tracking information
5. Update status as shipment progresses

### 6. Generating Reports
1. Go to **Reports** module
2. Select report type
3. Set date range and filters
4. View report data
5. Export if needed (CSV/PDF - to be implemented)

## Security Notes

- Change default admin password immediately
- Use strong passwords for all user accounts
- Regularly backup the database
- Keep PHP and MySQL updated
- Implement HTTPS in production environment
- Consider adding CSRF protection for forms
- Validate and sanitize all user inputs

## Customization

### Adding New Roles
1. Update the `role` ENUM in the `users` table
2. Add role checks in `config/config.php`
3. Update navigation in `includes/header.php`

### Modifying Reports
- Edit `modules/reports.php` to add new report types
- Add corresponding SQL queries and display logic

### Styling
- Modify `assets/css/style.css` to customize the appearance
- Update color variables in the `:root` section

## Troubleshooting

### Database Connection Error
- Verify MySQL is running
- Check database credentials in `config/database.php`
- Ensure database exists and is imported correctly

### Session Issues
- Check PHP session configuration
- Ensure cookies are enabled in browser
- Verify session storage directory is writable

### Page Not Found
- Check BASE_URL in `config/config.php`
- Verify .htaccess file exists (if using URL rewriting)
- Check Apache mod_rewrite is enabled

## Future Enhancements

- Email notifications for low stock alerts
- PDF export for reports
- CSV import for bulk data entry
- Advanced analytics and charts
- Mobile-responsive improvements
- API for external integrations
- Automated reordering based on forecasts
- Barcode scanning support
- Multi-language support

## Support

For issues or questions, please refer to the code comments or contact your system administrator.

## License

This project is provided as-is for educational and business use.

---

**Version:** 1.0.0  
**Last Updated:** 2024

