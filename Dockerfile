# Use the official PHP image as a base
FROM php:8.4-cli

# Set working directory
WORKDIR /app

# Copy application code
COPY . .

# Expose port (if using a web server, e.g., 8000 for built-in server)
EXPOSE 5000

# Default command (change app.php to your entrypoint if needed)
CMD ["php", "-S", "0.0.0.0:5000", "-t", "."]
