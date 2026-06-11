# Waze Feed Generator

This script fetches emergency vehicle positions from Traccar and exports them as a Waze incidents feed.

## Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/sebabahn/waze-feed.git
   cd waze-feed
   ```

2. **Create your local config:**
   ```bash
   cp config.php.example config.php
   ```

3. **Edit `config.php` with your Traccar credentials and settings:**
   ```php
   $TRACCAR_URL = 'http://your-traccar-server:8082';
   $USERNAME = 'your-email@example.com';
   $PASSWORD = 'your-password';
   ```

4. **Make the script executable and set up caching:**
   ```bash
   chmod +x waze-feed.php
   # Ensure temp directory is writable for cache
   chmod 755 /tmp
   ```

## Configuration

Edit `config.php` to customize:

- **Traccar Connection:**
  - `$TRACCAR_URL` - Traccar server URL
  - `$USERNAME` - Traccar login email
  - `$PASSWORD` - Traccar login password

- **Emergency Vehicles:**
  - `$EMERGENCY_GROUPS` - Array of group names containing emergency vehicles
  - `$FILTER_KEYWORDS` - Array of keywords in device names to identify emergency vehicles

- **Movement Detection:**
  - `$SPEED_THRESHOLD_KMH` - Only show vehicles moving faster than this (default: 5 km/h)
  - `$HISTORY_LOOKBACK_MINUTES` - Look back this many minutes for movement history (default: 10)

- **Caching:**
  - `$ENABLE_CACHE` - Enable/disable output caching (default: true)
  - `$CACHE_DURATION_SECONDS` - Cache validity period (default: 15)
  - `$CACHE_DIR` - Directory for cache files (default: system temp)

## Environment Variables

Instead of hardcoding credentials in `config.php`, you can use environment variables:

```bash
export TRACCAR_URL="http://traccar-server:8082"
export TRACCAR_USERNAME="user@example.com"
export TRACCAR_PASSWORD="password"
```

## Features

- ✅ Real-time emergency vehicle tracking
- ✅ Reverse geocoding for street names (OpenStreetMap Nominatim)
- ✅ Speed-based filtering (only shows moving vehicles)
- ✅ 10-minute movement history checking
- ✅ 15-second output caching for performance
- ✅ Comprehensive debug logging in XML comments
- ✅ Graceful error handling

## Usage

Access the feed at:
```
http://your-webserver/path/to/waze-feed.php
```

## Security Notes

- ⚠️ **Never commit `config.php` to version control** - it's in `.gitignore`
- Use environment variables for credentials in production
- Enable SSL verification in production environments
- Restrict API access to authorized applications

## Debug Logging

The XML feed includes detailed debug comments:

```xml
<!-- ID 1 → Name: 'LF1' | Current Speed: 45.23 km/h | Max Speed (last 10 min): 65.50 km/h | Last Moving: 2026-06-07 08:35:22 | Elapsed: 2 min | Moving: YES | Was Moving: YES -->
```

This shows:
- Current speed
- Maximum speed in lookback period
- Last detected movement time
- Minutes elapsed since movement
- Current and historical movement status

## Troubleshooting

If vehicles aren't showing up:
1. Check Traccar connectivity - verify credentials in `config.php`
2. Verify device groups/keywords match your Traccar device names/groups
3. Check movement status - vehicles must be moving faster than threshold
4. Review XML comments for detailed debug information
