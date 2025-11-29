# CodeIgniter 4 Entity Framework

Entity Framework Core for CodeIgniter 4 - A comprehensive ORM solution inspired by .NET Entity Framework Core.

## Installation

```bash
composer require yakupeyisan/codeigniter4-entity-framework
```

## Features

### ✅ Completed Features

1. **Core ORM Features**
   - Code First approach
   - Database First compatibility
   - Fluent API support
   - Data Annotations (Attributes) support

2. **Relationship Types**
   - One-to-One relationships
   - One-to-Many relationships
   - Many-to-Many relationships (Join entity and skip navigation)
   - Self-referencing relationships
   - Optional and Required relationships

3. **Key Management**
   - Primary Key
   - Composite Key
   - Foreign Key
   - Concurrency Tokens

4. **Loading Strategies**
   - Lazy Loading (proxies)
   - Eager Loading (Include / ThenInclude)
   - Explicit Loading

5. **LINQ Features**
   - IQueryable support
   - AsNoTracking / AsTracking
   - Projection (Select)
   - GroupBy
   - Join / Left Join
   - Raw SQL (FromSqlRaw)

6. **Migration System**
   - Add-Migration
   - Update-Database
   - Remove-Migration
   - Migration rollback
   - **Otomatik Migration Üretimi (MigrationGenerator)**
     - ApplicationDbContext'ten otomatik migration oluşturma
     - Mevcut tabloları kontrol ederek sadece yeni/değişiklikleri ekleme
     - Entity attribute'larından otomatik şema analizi

7. **Transaction and Concurrency**
   - BeginTransaction / Commit / Rollback
   - Optimistic Concurrency
   - RowVersion / Timestamp

8. **Advanced Features**
   - Value Converters
   - Owned Types (Complex Types)
   - Query Filters (Global Filters)
   - Change Tracking

9. **Repository Pattern**
   - Generic Repository
   - Unit of Work
   - Specification Pattern

10. **Audit and Soft Delete**
    - Audit fields (CreatedAt, UpdatedAt, DeletedAt)
    - Soft Delete pattern

## Usage Examples

### Creating DbContext

```php
use Yakupeyisan\CodeIgniter4\EntityFramework\CoreDbContext;
use App\Models\User;
use App\Models\Company;

class ApplicationDbContext extends DbContext
{
    protected function onModelCreating(): void
    {
        $this->entity(User::class)
            ->hasKey('Id')
            ->toTable('Users')
            ->property('Id')
                ->valueGeneratedOnAdd()
                ->entity()
            ->property('FirstName')
                ->hasMaxLength(100)
                ->isRequired()
                ->entity();
    }

    public function Users()
    {
        return $this->set(User::class);
    }
}
```

### Query Examples

```php
use Yakupeyisan\CodeIgniter4\EntityFramework\CoreDbContext;
use App\EntityFramework\ApplicationDbContext;

$context = new ApplicationDbContext();

// Query examples
$users = $context->Users()
    ->where(fn($u) => $u->CompanyId === 1)
    ->include('Company')
    ->include('UserDepartments')
        ->thenInclude('Department')
    ->orderBy(fn($u) => $u->LastName)
    ->toList();

// First or default
$user = $context->Users()
    ->where(fn($u) => $u->Id === 1)
    ->firstOrDefault();

// AsNoTracking
$users = $context->Users()
    ->asNoTracking()
    ->toList();

// Count
$count = $context->Users()->count();

// Any
$hasUsers = $context->Users()->any();
```

### Repository Pattern

```php
use Yakupeyisan\CodeIgniter4\EntityFramework\Repository\UnitOfWork;
use App\EntityFramework\ApplicationDbContext;

$context = new ApplicationDbContext();
$unitOfWork = new UnitOfWork($context);

$userRepo = $unitOfWork->getRepository(User::class);

// Get by ID
$user = $userRepo->getById(1);

// Add
$newUser = new User();
$newUser->FirstName = "John";
$newUser->LastName = "Doe";
$userRepo->add($newUser);

// Update
$user->FirstName = "Jane";
$userRepo->update($user);

// Remove
$userRepo->remove($user);

// Save changes
$unitOfWork->saveChanges();
```

### Transaction Usage

```php
$context = new ApplicationDbContext();

$context->beginTransaction();
try {
    $user = new User();
    $user->FirstName = "John";
    $user->LastName = "Doe";
    $context->add($user);
    
    $company = new Company();
    $company->Name = "New Company";
    $context->add($company);
    
    $context->saveChanges();
    $context->commit();
} catch (\Exception $e) {
    $context->rollback();
    throw $e;
}
```

### Migration Usage

#### Manuel Migration Oluşturma

```php
use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\MigrationManager;

$migrationManager = new MigrationManager();

// Add migration
$migrationManager->addMigration('AddUserTable', function($builder) {
    $builder->createTable('Users', function($columns) {
        $columns->integer('Id')->primaryKey()->autoIncrement();
        $columns->integer('CompanyId')->notNull();
        $columns->string('FirstName', 100)->notNull();
        $columns->string('LastName', 100)->notNull();
        $columns->dateTime('CreatedAt')->nullable();
    });
}, function($builder) {
    $builder->dropTable('Users');
});

// Update database
$migrationManager->updateDatabase();

// Rollback
$migrationManager->rollbackMigration(1);
```

#### Otomatik Migration Üretimi (MigrationGenerator)

MigrationGenerator, ApplicationDbContext'inizi analiz ederek otomatik olarak migration kodları üretir. Bu özellik sayesinde entity'lerinizdeki değişiklikleri manuel olarak migration'a dönüştürmenize gerek kalmaz.

##### Özellikler

- ✅ **Otomatik Entity Analizi**: ApplicationDbContext'teki tüm entity'leri otomatik olarak bulur
- ✅ **Attribute Desteği**: Entity attribute'larından (Table, Key, Column, ForeignKey, vb.) şema bilgilerini çıkarır
- ✅ **Akıllı Migration**: Mevcut tabloları kontrol eder, sadece yeni tabloları veya değişiklikleri ekler
- ✅ **Bağımlılık Yönetimi**: Foreign key bağımlılıklarına göre tabloları doğru sırada oluşturur
- ✅ **Rollback Desteği**: Down migration'ları otomatik olarak üretir

##### Kullanım

**1. ApplicationDbContext Hazırlama**

Önce entity'lerinizi ve ApplicationDbContext'inizi hazırlayın:

```php
// app/EntityFramework/ApplicationDbContext.php
use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;
use App\Models\User;
use App\Models\Company;

class ApplicationDbContext extends DbContext
{
    protected function onModelCreating(): void
    {
        // Fluent API ile entity konfigürasyonları
        $this->entity(User::class)
            ->hasKey('Id')
            ->toTable('Users');
    }

    public function Users()
    {
        return $this->set(User::class);
    }

    public function Companies()
    {
        return $this->set(Company::class);
    }
}
```

**2. Entity Tanımlamaları (Attribute ile)**

```php
// app/Models/User.php
use Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Table;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\DatabaseGenerated;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Required;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\MaxLength;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\ForeignKey;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Index;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\AuditFields;

#[Table("Users")]
#[Index("CompanyId")]
#[AuditFields(createdAt: true, updatedAt: true, deletedAt: true)]
class User extends Entity
{
    #[Key]
    #[DatabaseGenerated(DatabaseGenerated::IDENTITY)]
    public int $Id;

    #[Required]
    #[MaxLength(100)]
    public string $FirstName;

    #[Required]
    #[MaxLength(100)]
    public string $LastName;

    #[ForeignKey("Company")]
    public int $CompanyId;

    public ?Company $Company = null;
}
```

```php
// app/Models/Company.php
use Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Table;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\DatabaseGenerated;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Required;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\MaxLength;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\AuditFields;

#[Table("Companies")]
#[AuditFields(createdAt: true, updatedAt: true)]
class Company extends Entity
{
    #[Key]
    #[DatabaseGenerated(DatabaseGenerated::IDENTITY)]
    public int $Id;

    #[Required]
    #[MaxLength(255)]
    public string $Name;

    public ?string $Description = null;
}
```

**3. Otomatik Migration Oluşturma**

```php
use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\MigrationManager;

$migrationManager = new MigrationManager();

// Otomatik olarak ApplicationDbContext'ten migration üret
$fileName = $migrationManager->addMigration('InitialCreate');
// Dosya: 20240101120000_InitialCreate.php

// Migration dosyası otomatik olarak oluşturulur:
// - Yeni tablolar için createTable
// - Mevcut tablolar için addColumn, createIndex, addForeignKey
// - Down migration'ları otomatik olarak üretilir
```

**4. Migration'ı Veritabanına Uygulama**

```php
// Tüm bekleyen migration'ları uygula
$migrationManager->updateDatabase();

// Belirli bir migration'a kadar uygula
$migrationManager->updateDatabase('20240101120000_InitialCreate');
```

**5. Migration Rollback**

```php
// Son migration'ı geri al
$migrationManager->rollbackMigration(1);

// Son 3 migration'ı geri al
$migrationManager->rollbackMigration(3);
```

##### MigrationGenerator Nasıl Çalışır?

1. **Entity Keşfi**: ApplicationDbContext'teki public metodları (Users, Companies, vb.) analiz eder
2. **Reflection Analizi**: Her entity için Reflection kullanarak attribute'ları ve property'leri inceler
3. **Şema Çıkarımı**: 
   - Table attribute'undan tablo adını alır
   - Key attribute'undan primary key'i belirler
   - Column attribute'undan kolon tipini ve özelliklerini çıkarır
   - ForeignKey attribute'undan ilişkileri tespit eder
   - Index attribute'undan index'leri belirler
   - AuditFields attribute'undan audit kolonlarını ekler
4. **Mevcut Şema Kontrolü**: Veritabanındaki mevcut tabloları kontrol eder
5. **Akıllı Migration**: 
   - Yeni tablolar için `createTable` kullanır
   - Mevcut tablolar için sadece yeni kolonlar, indexler ve foreign key'ler ekler
6. **Bağımlılık Sıralaması**: Foreign key bağımlılıklarına göre tabloları doğru sırada oluşturur

##### Örnek: Üretilen Migration Kodu

```php
// Otomatik üretilen migration dosyası örneği
namespace App\Database\Migrations;

use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\Migration;
use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\MigrationBuilder;
use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\ColumnBuilder;

class Migration_20240101120000_InitialCreate extends Migration
{
    public function up(): void
    {
        $builder = new MigrationBuilder($this->connection);
        
        // Companies table (foreign key bağımlılığı olmadığı için önce)
        $builder->createTable('Companies', function(ColumnBuilder $columns) {
            $columns->integer('Id')->primaryKey()->autoIncrement()->notNull();
            $columns->string('Name', 255)->notNull();
            $columns->string('Description', 255)->nullable();
            $columns->datetime('CreatedAt')->nullable();
            $columns->datetime('UpdatedAt')->nullable();
        });
        
        // Users table (Companies'e bağımlı)
        $builder->createTable('Users', function(ColumnBuilder $columns) {
            $columns->integer('Id')->primaryKey()->autoIncrement()->notNull();
            $columns->string('FirstName', 100)->notNull();
            $columns->string('LastName', 100)->notNull();
            $columns->integer('CompanyId')->notNull();
            $columns->datetime('CreatedAt')->nullable();
            $columns->datetime('UpdatedAt')->nullable();
            $columns->datetime('DeletedAt')->nullable();
        });
        
        // Index oluşturma
        $builder->createIndex('Users', 'IX_Users_CompanyId', ['CompanyId'], false);
        
        // Foreign key oluşturma
        $builder->addForeignKey(
            'Users',
            'FK_Users_Companies',
            ['CompanyId'],
            'Companies',
            ['Id'],
            'CASCADE'
        );
        
        $builder->execute();
    }

    public function down(): void
    {
        $builder = new MigrationBuilder($this->connection);
        
        // Rollback işlemleri (ters sırada)
        $builder->dropTable('Users');
        $builder->dropTable('Companies');
        
        $builder->execute();
    }
}
```

##### İkinci Migration Örneği (Mevcut Tablolara Yeni Kolon Ekleme)

Entity'nize yeni bir property eklediğinizde:

```php
// User entity'sine Email eklendi
#[Required]
#[MaxLength(255)]
public string $Email;
```

Yeni migration oluşturulduğunda:

```php
// Otomatik üretilen migration
public function up(): void
{
    $builder = new MigrationBuilder($this->connection);
    
    // Companies tablosu zaten var, değişiklik yok
    
    // Users tablosuna yeni kolon ekle
    $builder->addColumn('Users', 'Email', 'VARCHAR(255)', ['null' => false]);
    
    $builder->execute();
}

public function down(): void
{
    $builder = new MigrationBuilder($this->connection);
    
    // Yeni eklenen kolonu kaldır
    $builder->dropColumn('Users', 'Email');
    
    $builder->execute();
}
```

##### Desteklenen Attribute'lar

MigrationGenerator aşağıdaki attribute'ları destekler:

- `#[Table("TableName")]` - Tablo adı
- `#[Key]` - Primary key
- `#[DatabaseGenerated(DatabaseGenerated::IDENTITY)]` - Auto increment
- `#[Column("ColumnName", "VARCHAR(255)")]` - Kolon adı ve tipi
- `#[Required]` - NOT NULL
- `#[MaxLength(255)]` - Maksimum uzunluk
- `#[ForeignKey("NavigationProperty")]` - Foreign key ilişkisi
- `#[Index("ColumnName")]` veya `#[Index(["Col1", "Col2"], isUnique: true)]` - Index
- `#[AuditFields(createdAt: true, updatedAt: true, deletedAt: true)]` - Audit kolonları

##### İpuçları

1. **İlk Migration**: İlk migration'ınızı oluştururken tüm entity'lerinizi ApplicationDbContext'e eklediğinizden emin olun.

2. **Yeni Entity Ekleme**: Yeni bir entity eklediğinizde, ApplicationDbContext'e ilgili DbSet metodunu ekleyin:
   ```php
   public function Products()
   {
       return $this->set(Product::class);
   }
   ```

3. **Mevcut Tablolar**: MigrationGenerator mevcut tabloları kontrol eder, bu yüzden aynı tabloyu tekrar oluşturmaz.

4. **Foreign Key Bağımlılıkları**: Entity'leriniz arasındaki foreign key ilişkileri otomatik olarak tespit edilir ve doğru sırada oluşturulur.

5. **Hata Ayıklama**: Migration oluşturma sırasında hata olursa, `error_log` dosyalarını kontrol edin. MigrationGenerator detaylı log mesajları üretir.

### Fluent API Configuration

```php
protected function onModelCreating(): void
{
    $this->entity(User::class)
        ->hasKey('Id')
        ->toTable('Users')
        ->property('Id')
            ->valueGeneratedOnAdd()
            ->entity()
        ->property('FirstName')
            ->hasMaxLength(100)
            ->isRequired()
            ->entity()
        ->hasOne('Company')
            ->hasForeignKey('CompanyId')
            ->withMany('Users')
            ->onDelete('CASCADE')
            ->entity()
        ->hasIndex('CompanyId');
}
```

### Data Annotations (Attributes)

```php
use Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Table;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\DatabaseGenerated;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Required;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\MaxLength;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\ForeignKey;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InverseProperty;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Index;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\AuditFields;

#[Table("Users")]
#[Index("CompanyId")]
#[AuditFields(createdAt: true, updatedAt: true)]
class User extends Entity
{
    #[Key]
    #[DatabaseGenerated(DatabaseGenerated::IDENTITY)]
    #[Column("Id", "INT")]
    public int $Id;

    #[Required]
    #[MaxLength(100)]
    #[Column("FirstName", "VARCHAR(100)")]
    public string $FirstName;

    #[ForeignKey("Company")]
    #[Column("CompanyId", "INT")]
    public int $CompanyId;

    #[InverseProperty("Users")]
    public ?Company $Company = null;
}
```

## Entity Structure

All entities must extend the `Entity` base class:

```php
use Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity;

class User extends Entity
{
    // Properties with attributes
}
```

## Package Structure

```
src/
├── Attributes/          # Data Annotations (Attributes)
│   ├── Table.php
│   ├── Key.php
│   ├── Column.php
│   ├── ForeignKey.php
│   └── ...
├── Configuration/       # Fluent API
│   ├── EntityTypeBuilder.php
│   ├── PropertyBuilder.php
│   └── ...
├── Core/               # Core classes
│   ├── Entity.php
│   ├── DbContext.php
│   └── ...
├── Migrations/         # Migration system
│   ├── Migration.php
│   ├── MigrationBuilder.php
│   └── MigrationManager.php
├── Query/              # Query building
│   ├── IQueryable.php
│   ├── Queryable.php
│   └── AdvancedQueryBuilder.php
├── Repository/         # Repository pattern
│   ├── IRepository.php
│   ├── Repository.php
│   ├── UnitOfWork.php
│   └── Specification/
└── Support/            # Supporting classes
    ├── ValueConverter.php
    └── OwnedType.php
```

## Requirements

- PHP 8.1 or higher
- CodeIgniter 4.0 or higher

## License

MIT

## Notes

- This system is compatible with CodeIgniter 4
- All features are designed to be 100% compatible with EF Core
- Production-ready code structure
- Both Data Annotations and Fluent API are supported

## Development Status

✅ Core infrastructure completed
✅ All entities updated
✅ Query builder implementation completed
✅ Repository and Unit of Work patterns added
✅ Migration system ready

## Next Steps

- Expression tree parsing (advanced WHERE clause support)
- Compiled queries (performance optimization)
- Batch operations improvements
- Lazy loading proxy implementation

