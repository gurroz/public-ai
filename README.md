# Product Desk

A local Laravel 12 application for salespeople to browse large, company-specific product CSVs. No login, cloud services, JavaScript packages, or frontend build step. Blade, plain JavaScript and CSS, and an on-device SQLite database.

## Run on your computer

Install **PHP 8.2+**, **Composer 2**, and PHP extensions required by Laravel/PHPUnit: PDO SQLite, mbstring, DOM/XML/XMLWriter, ctype, curl, fileinfo, filter, hash, openssl, pcre, session and tokenizer. ZIP or an unzip executable is needed for Composer archives. PHP is available in packages such as Laravel Herd; enable SQLite in the PHP installation used by your terminal. SQLite JSON functions are required for list filters (included by default in SQLite 3.38+).

Open a terminal in this folder:

```sh
composer run setup
composer run dev
```

Visit **http://127.0.0.1:8000**. The server binds to your computer's loopback address. Each salesperson installs their own copy. Keep the terminal running while using the application. Stop with Ctrl+C.

Setup installs dependencies, creates `.env`, generates the local Laravel encryption key, creates the SQLite file, and runs migrations. This key is generated automatically; users do not need credentials or an account. For an existing installation, use `composer install` and `php artisan migrate` rather than regenerating its key.

Click **Import CSV**, or **Explore sample catalogue**. The sample file is also in `samples/products.csv`.

## Large imports

The browser importer supports files up to 500 MB, subject to PHP's upload and execution limits. The command-line importer avoids HTTP upload/time limits and is recommended for large catalogues:

```sh
php -d memory_limit=256M artisan catalog:import "/path/to/products.csv"
```

For browser uploads, configure `upload_max_filesize=500M`, `post_max_size=512M`, and an appropriate `max_execution_time` in the PHP configuration used by the server, then restart it. CLI imports have no application-imposed file-size limit. Disk space and import duration depend on the catalogue. Do not import two catalogues simultaneously.

CSV processing uses two streaming passes: validation/type inference across every record, then bounded inserts in one transaction. It does not load the file into an array. All generated product columns have indexes. Pipe-separated list filters scan their JSON items; the scalar-column indexes do not accelerate list membership queries. Queries return only 25, 50, or 100 rows at a time. Substring searches still require scans; indexes primarily help equality, range filters and sorting. Importing creates a replacement table, so allow room for both the old and new catalogue plus SQLite transaction files.

## CSV contract and types

- UTF-8, comma-delimited CSV with a header. UTF-8 BOM, quoted commas, quoted newlines and empty lines are supported.
- 2–100 columns, with non-empty, case-insensitively unique headers. The first field is ID, the second is name, regardless of header spelling. Both must be present in every product. IDs are preserved as text, including leading zeros. Duplicate product IDs are allowed; source records are retained independently.
- Remaining columns are inferred from all non-blank items, including items inside pipe-separated lists: finite decimal/scientific numbers, strict ISO dates (`YYYY-MM-DD`), or text. A mixed-type column becomes text; an entirely empty column is text.
- Whitespace around cells is trimmed. Blank custom cells are stored as NULL and do not match any condition, including negative conditions.
- Numbers use SQLite REAL (floating point). This is a catalogue tool, not an exact-decimal accounting engine. Locale-formatted numbers (`1,200.00`, currency signs, decimal commas) and non-ISO dates remain text. Custom numeric identifiers should contain a non-numeric prefix if they must stay text.
- Every import replaces the previous catalogue and dynamically rebuilds the table schema. A validation/database failure rolls back the replacement. The original uploaded CSV is not retained by the app.

## Filters

Text: **equals, not equals, contains, does not contain**.

Numbers and dates: **greater than, greater than or equal, less than, less than or equal, between**. Between includes both bounds. Only operators valid for each column type appear in the builder; the server validates them again.

Every condition after the first has an **AND / OR** selector. Standard SQL precedence applies: **AND before OR**. For example:

```text
Name equals "Iphone 9" OR Name equals "Galaxy 23" AND Price between 900 AND 2000
```

means `Iphone 9 OR (Galaxy 23 AND Price between 900 AND 2000)`. There is no arbitrary nested-group editor. Up to 50 conditions are supported. The interface displays the current draft expression; click **Apply filters** to run it. Reset clears the filters and name search.

Text equality is case-sensitive. Contains and name search use SQLite LIKE semantics (ASCII case-insensitive); `%`, `_`, and backslashes are escaped and matched literally. Names/values are bound parameters, and SQL columns are generated internal identifiers rather than CSV header strings. Search is ANDed with the entire filter expression. Table headers sort results (by the first item for list columns), and the Columns menu controls visibility.

## Multiple values in one cell

Use `|` inside a CSV cell to separate values:

```csv
ID,Name,Colours,Prices,Available dates
1,Phone,red|blue,900|1200,2024-01-01|2024-03-01
2,Tablet,green,800,2024-02-01
```

- `Colours equals blue` matches the first product. Equality matches an entire item, so it does not match `blueberry`.
- `Colours contains blue` matches any item containing that substring.
- `Colours not equals blue` and `Colours does not contain blue` match only products with a non-empty list where **no** item matches the corresponding positive condition.
- Numeric/date comparisons match if **any one item** satisfies the comparison. Between requires the **same item** to satisfy both inclusive bounds. For example, `5|25` does not match between 10 and 20.
- Separate AND conditions may match different items in the same cell: `equals red AND equals blue` matches `red|blue`. Each matching product appears once.
- Spaces around items are trimmed; empty items are ignored and duplicates removed. `red | | blue | red` becomes `red | blue`. A cell with no remaining items is NULL and does not match negative filters either. ID and name still require at least one non-empty item.
- Single values and lists can coexist in a column. Types are inferred across every item. `10|20` is numeric; `10|unknown` makes that column text. IDs and names always remain text.
- The table displays lists separated by ` | `. The query API returns arrays for list columns, including a one-item array for a scalar record in that column. Empty cells remain NULL.
- Commas still separate CSV fields; quote any field containing commas. A quoted cell `"red,blue"` is one text value. There is no escape for a literal pipe: every `|` in a cell is a separator. Filter input is a single comparison value, not another pipe-separated list.

**Reimport your CSV after updating** to enable list semantics on an existing catalogue. Older imports remain queryable with their original scalar behavior until replaced. No database migration is necessary because import rebuilds the product table.

## Tests

```sh
composer test
```

Or run PHPUnit directly:

```sh
php vendor/bin/phpunit
```

Tests use a separate in-memory SQLite database and a test-only encryption key; they do not modify your local catalogue. Coverage includes uploads, changing schemas, mixed types, malformed imports preserving the old catalogue, BOM/quoted fields, all typed operators, AND/OR precedence, literal wildcard matching, SQL injection inputs, validation, nulls, sorting, pagination, the sample/CLI flows, and a 20,000-row import.

## Files and maintenance

Local catalogue: `database/database.sqlite`. Close the app before backing it up. Runtime logs and file sessions live under `storage/`. Source code is in `app/Services`, `app/Http/Controllers`, `resources/views/products.blade.php`, and `public/app.*`.

No CDN assets, telemetry, login provider or external service is used by the application. Composer needs network access for initial dependency installation. Keep the server on `127.0.0.1`; this application is intended for trusted local use.

## Why these folders are included

- `app/`: the application's import, filter and controller code.
- `bootstrap/`: Laravel startup files. This is unrelated to the Bootstrap CSS library and must stay.
- `database/`: the catalogue migration and, after setup, your local SQLite catalogue. Must stay.
- `vendor/`: Composer-installed framework dependencies. Required to run, but intentionally excluded from the source ZIP. `composer run setup` recreates it. Do not edit files here.
- `public/`, `resources/`, `routes/`, `config/`, `storage/`: the UI, routing, framework settings and writable runtime directories. Must stay.
- `tests/`: automated checks; retained as part of the project.

The minimal source package removes unused account models, user/cache/job migrations, factories, seeders, and optional Tinker, Faker, Pail, Pint and Sail packages. It preserves Laravel and all requested features and tests. For a runtime installation without test dependencies, use `composer install --no-dev` after initial setup; keep the development dependencies when running the test suite.
