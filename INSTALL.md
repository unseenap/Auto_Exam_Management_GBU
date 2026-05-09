# INSTALLATION GUIDE
## Automated Examination Seating Management System

### Quick Setup (5 Minutes)

#### Step 1: Install XAMPP
1. Download XAMPP from https://www.apachefriends.org/
2. Install XAMPP (Default location: C:\xampp)
3. Open XAMPP Control Panel
4. Start **Apache** and **MySQL** services

#### Step 2: Copy Project Files
1. Copy the entire folder "Automated Seating management" to:
   ```
   C:\xampp\htdocs\
   ```
2. Final path should be:
   ```
   C:\xampp\htdocs\Automated Seating management\
   ```

#### Step 3: Create Database
1. Open your web browser
2. Go to: http://localhost/phpmyadmin
3. Click "New" to create a database
4. Database name: **exam_management**
5. Collation: **utf8mb4_unicode_ci**
6. Click "Create"

#### Step 4: Import Database Schema
1. Click on the **exam_management** database (left sidebar)
2. Click **Import** tab
3. Click **Choose File**
4. Navigate to: `C:\xampp\htdocs\Automated Seating management\database\schema.sql`
5. Click **Go** button at the bottom
6. Wait for success message

#### Step 5: Access the System
1. Open web browser
2. Go to: http://localhost/Automated%20Seating%20management/
   OR: http://localhost/Automated Seating management/
3. You should see the login page

#### Step 5.1: Install PHP PDF Dependency (Required for Seating Plan PDF)
1. Open terminal in project root:
   ```
   C:\xampp\htdocs\Automated Seating management\
   ```
2. Run:
   ```bash
   composer install
   ```
3. This installs `dompdf/dompdf` used by `api/seating-pdf.php`.

#### Step 6: Login
**Admin Login:**
- Username: `admin`
- Password: `admin123`
- Role: Select "Admin"

---

## Verification Checklist

✅ XAMPP installed and running
✅ Apache service started (green in XAMPP)
✅ MySQL service started (green in XAMPP)
✅ Database created (exam_management)
✅ Schema imported (10 tables created)
✅ Can access http://localhost/phpmyadmin
✅ Can access login page
✅ Can login with admin credentials

---

## Common Issues & Solutions

### Issue 1: Apache won't start
**Solution:**
- Port 80 might be in use
- Open XAMPP Config → Apache → httpd.conf
- Change `Listen 80` to `Listen 8080`
- Restart Apache
- Access via: http://localhost:8080/

### Issue 2: MySQL won't start
**Solution:**
- Port 3306 might be in use
- Stop other MySQL services
- Or change port in XAMPP Config

### Issue 3: Can't access the website
**Solution:**
- Check if Apache is running (green)
- Verify URL: http://localhost/Automated%20Seating%20management/
- Clear browser cache
- Try different browser

### Issue 4: Database connection error
**Solution:**
- Check MySQL is running
- Open `config/database.php`
- Verify credentials:
  ```php
  define('DB_HOST', 'localhost');
  define('DB_USER', 'root');
  define('DB_PASS', '');
  define('DB_NAME', 'exam_management');
  ```

### Issue 5: Login fails
**Solution:**
- Check if schema.sql was imported
- Verify users table has admin record
- Clear browser cookies
- Use correct credentials

### Issue 6: Blank page after login
**Solution:**
- Enable PHP error display
- Check PHP error logs in `C:\xampp\php\logs\`
- Verify all files were copied correctly

---

## File Permissions (For Linux/Mac)
```bash
chmod -R 755 /path/to/project
chmod -R 777 /path/to/project/uploads (if needed)
```

---

## Testing the System

### 1. Test Seating Allocation
- Ensure student data already exists in the database
- Add at least 2 rooms
- Create an exam
- Go to Seating Allocation
- Click "Allocate Seating"
- View seating chart

### 2. Test Invigilation
- Add at least 3 faculty members
- Ensure seating is allocated
- Go to Invigilation
- Click "Allocate Duties"
- View duty chart

### 3. Test Student Portal
- Login as student (use roll number)
- View exam schedule
- View seating allocation
- Print seating slip

---

## Database Backup

### To Backup:
1. Go to phpMyAdmin
2. Select exam_management database
3. Click Export tab
4. Click Go
5. Save the .sql file

### To Restore:
1. Create new database
2. Click Import
3. Choose backup file
4. Click Go

---

## Uninstallation

1. Delete project folder:
   ```
   C:\xampp\htdocs\Automated Seating management\
   ```

2. Drop database in phpMyAdmin:
   - Select exam_management
   - Click "Drop"
   - Confirm

---

## System Requirements

**Minimum:**
- RAM: 2 GB
- Disk Space: 100 MB
- PHP: 7.4+
- MySQL: 5.7+
- Apache: 2.4+

**Recommended:**
- RAM: 4 GB
- Disk Space: 500 MB
- PHP: 8.0+
- MySQL: 8.0+
- SSD Storage

---

## Next Steps After Installation

1. **Change Default Passwords**
   - Login as admin
   - Change admin password
   - Change all default passwords

2. **Add Your Data**
   - Add students (or upload CSV)
   - Add faculty members
   - Add examination rooms
   - Create exam schedules

3. **Configure System**
   - Set up exam dates
   - Allocate seating
   - Assign invigilation duties
   - Test attendance marking

4. **Train Users**
   - Train faculty on duty management
   - Inform students about portal access
   - Provide roll numbers for login

---

## Support

For technical support:
1. Check README.md for detailed documentation
2. Review troubleshooting section
3. Check PHP error logs
4. Verify database connection

---

## Security Notes

⚠️ **Important:**
- Change all default passwords immediately
- Don't use in production without HTTPS
- Regular database backups recommended
- Keep XAMPP updated
- Restrict phpMyAdmin access

---

## Congratulations! 🎉

Your Exam Management System is now ready to use!

**First Time Users:**
1. Login as admin
2. Add some test data
3. Try seating allocation
4. Generate reports
5. Test student portal

**For Production:**
1. Change all passwords
2. Add real data
3. Train staff
4. Take regular backups
5. Monitor system performance

---

**Need Help?**
Refer to README.md for complete documentation.
