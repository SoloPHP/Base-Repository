# Installation

## Requirements

- PHP 8.3 or higher
- Doctrine DBAL ^4.3

## Composer

Install the package via Composer:

```bash
composer require solophp/base-repository
```

## Dependencies

The package has minimal dependencies:

```json
{
    "require": {
        "php": ">=8.3",
        "doctrine/dbal": "^4.3"
    }
}
```

## Verify Installation

```php
<?php

require 'vendor/autoload.php';

use Solo\BaseRepository\BaseRepository;

// If no errors, installation is successful
echo "Solo Base Repository installed successfully!";
```

## Next Steps

- [Quick Start](/guide/quick-start) — Create your first repository
- [Configuration](/guide/configuration) — Learn about configuration options
