# Backend Developer Assignment – User Data Management & Twitter OAuth API

## Project Overview
This project is a Symfony-based RESTful API for user management with Twitter OAuth integration. Key features include:
- User data management through CSV files
- Twitter OAuth authentication
- Database backup and restore functionality
- Asynchronous email notifications

## Technology Stack
- PHP 8.2
- Symfony 7.2
- MySQL 8.0
- Docker & Docker Compose
- Nginx
- MailCatcher for email testing
- PHPUnit for testing

## Installation and Setup

### Prerequisites
- Docker
- Docker Compose
- Git

### Installation Steps

1. Clone the repository:
```bash
git clone <repository-url>
cd <repository-name>
```

2. Create .env.local file and configure environment variables:
```bash
cp .env .env.local
```
Edit .env.local and add your Twitter API keys:
```
TWITTER_API_KEY=your_twitter_api_key
TWITTER_API_SECRET=your_twitter_api_secret
```

3. Start Docker containers:
```bash
docker-compose up -d
```

4. Install dependencies:
```bash
docker-compose exec php composer install
```

5. Create database and run migrations:
```bash
docker-compose exec php bin/console doctrine:migrations:migrate
```

## Testing

### Running Tests
```bash
docker-compose exec php bin/phpunit
```

The project includes comprehensive unit tests for:
- User data upload via CSV
- User data validation
- Email notification system
- Database operations
- API endpoints

Tests use mocks for:
- Database operations (EntityManager)
- Email sending (Mailer)
- File system operations

### Test Coverage
Key test scenarios include:
- Successful user data upload
- Invalid CSV structure handling
- Invalid email format validation
- Invalid role validation
- User listing functionality

## Available Services

- **API**: http://localhost:8080
- **MySQL**: localhost:3307
- **MailCatcher** (for email testing): http://localhost:1080

## API Endpoints

### User Management
- `POST /api/upload` - Upload CSV file with user data
  - CSV format: name,email,username,address,role
  - Validates email format
  - Validates user roles (ROLE_USER, ROLE_ADMIN)
  - Sends welcome email to each imported user
- `GET /api/users` - Get list of all users

### Database Management
- `GET /api/backup` - Create database backup
  - Returns SQL file with complete database dump
  - File includes table structure and data
  - Filename format: `backup_YYYY-MM-DD_HH-mm-ss.sql`
- `POST /api/restore` - Restore database from backup
  - Accepts SQL file via form-data with key 'file'
  - Restoration is performed within a transaction
  - Automatic rollback in case of errors

### Database Console Commands

In addition to API endpoints, the following console commands are available:

```bash
# Create backup
bin/console app:database:backup

# Restore from backup
bin/console app:database:restore path/to/backup.sql
```

Backup Features:
- All backups are stored in the `var/backup/` directory
- Backup file permissions: 0750 (owner read/write, group read)
- Transactions are used during restore to ensure data integrity
- Proper handling of NULL values and special character escaping

### Twitter OAuth
- `GET /auth/twitter` - Initiate Twitter authentication process
- `GET /auth/twitter/callback` - Callback URL for handling Twitter response

## Testing the API

1. Import Postman collection from `Backend-Assignment.postman_collection.json`
2. Use the sample `data.csv` file to test user upload functionality
3. Check email sending through MailCatcher web interface (http://localhost:1080)

### Sample CSV Format
```csv
name,email,username,address,role
John Doe,john@example.com,johndoe,"123 Main St, City",ROLE_USER
Jane Smith,jane@example.com,janesmith,"456 Park Ave, Town",ROLE_ADMIN
```

### Error Handling
The API provides detailed error messages for:
- Invalid CSV structure
- Invalid email format
- Invalid role values
- Missing required fields
- File size limits (max 5MB)
- File type validation (CSV only)

## Twitter OAuth Setup

1. Create an application in [Twitter Developer Portal](https://developer.twitter.com/en/portal/dashboard)
2. Get API keys (API Key and API Secret)
3. Set Callback URL: http://localhost:8080/auth/twitter/callback
4. Update .env.local with obtained keys

## Email Notifications
- Welcome emails are sent to users upon successful import
- All email notifications are sent asynchronously
- MailCatcher is used for testing (http://localhost:1080)
- For production, configure a real SMTP server in .env.local

## Security Considerations
- Input validation for all user data
- SQL injection prevention through Doctrine ORM
- XSS prevention through proper escaping
- CSRF protection for form submissions
- Rate limiting for API endpoints
- Secure password hashing (for Twitter OAuth)

## Demo Video

[Demo video link will be added after recording]

## Additional Information

### Working with CSV Files
Example CSV structure:
```csv
name,email,username,address,role
John Doe,john@example.com,johndoe,"123 Main St, City",ROLE_USER
Jane Smith,jane@example.com,janesmith,"456 Park Ave, Town",ROLE_ADMIN
```

### Database Backup
- Backups are stored in `var/backup/` directory
- Filename format: `backup_YYYY-MM-DD_HH-mm-ss.sql`

### Email Notifications
- All email notifications are sent asynchronously
- MailCatcher is used for testing
- For production, configure a real SMTP server in .env.local 