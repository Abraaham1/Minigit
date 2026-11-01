# MiniGit - authentication step

Login, signup, session handling and home page (PHP + MySQL).

## Setup (Ubuntu)
1. Copy everything into /var/www/dev  (DocumentRoot = /var/www/dev/public)
2. Create the database:   sudo mysql < /var/www/dev/db/schema.sql
3. Change the DB password in db/schema.sql AND config/database.php (they must match)
4. Open http://dev.local

## Files
public/    pages the browser can open (login, signup, logout, home)
config/    database connection (outside public/)
includes/  session, auth guards, helpers, shared header/footer
db/        schema.sql for the whole project
