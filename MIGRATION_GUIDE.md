# CodeIgniter4 Entity Framework Geçiş Rehberi

Bu doküman, bu paketi kullanan mevcut mimarilerin yeni sürüme (PDO-only + strict security + streaming) güvenli ve kontrollü şekilde geçmesi için hazırlanmıştır.

## Kısa Özet

Yeni sürümle birlikte:

- Bağlantı katmanı `CodeIgniter Database` yerine doğrudan `PDO` kullanır.
- Raw SQL güvenliği varsayılan olarak **strict ON** gelir.
- Büyük veri setleri için `stream()` API'si eklenmiştir.
- Güvensiz SQL kalıpları runtime'da engellenir.

## Kırıcı Değişiklikler

1. **PDO-only mimari**
   - `BaseConnection` / `Config\Database::connect()` bağımlılığı kaldırılmıştır.
   - `DbContext` artık PDO veya `PdoAdapter` ile çalışır.

2. **Strict SQL güvenlik varsayılanı**
   - Raw SQL giriş noktalarında (ör. `fromSqlRaw`, string `where`, `joinRaw`) koruma zorunludur.
   - Çoklu statement ve tehlikeli DDL/DCL token'ları engellenir.
   - Placeholder (`?`) ve parametre sayısı uyuşmazsa exception fırlatılır.

3. **Raw SQL davranışı**
   - Önceki sürümde çalışan bazı gevşek raw SQL kullanımları artık bloklanabilir.

## Geçiş Ön Koşulları

- PHP `ext-pdo` aktif olmalı.
- Hedef veritabanı sürücüleri kurulu olmalı:
  - `pdo_mysql`
  - `pdo_pgsql`
  - `pdo_sqlsrv`
  - `pdo_sqlite`

## 1) Bağlantı Yapılandırması

### Ortam değişkenleri ile

```env
ENTITY_FRAMEWORK_PDO_DSN=mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4
ENTITY_FRAMEWORK_PDO_USER=root
ENTITY_FRAMEWORK_PDO_PASSWORD=secret

ENTITY_FRAMEWORK_STRICT_SQL_SECURITY=true
ENTITY_FRAMEWORK_AUDIT_RAW_SQL=true
```

### Kod üzerinden PDO ile

```php
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4',
    'root',
    'secret',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]
);

$context = new ApplicationDbContext($pdo);
```

## 2) Raw SQL Kullanımlarını Gözden Geçirme

### Eski riskli yaklaşım (artık önerilmez)

```php
$q->fromSqlRaw("SELECT * FROM users WHERE email = '$email'");
```

### Yeni güvenli yaklaşım

```php
$q->fromSqlRaw("SELECT * FROM users WHERE email = ?", [$email]);
```

### Dikkat edilmesi gerekenler

- SQL içinde `?` sayısı ile parametre dizisi uzunluğu birebir aynı olmalı.
- Tek çağrıda birden fazla statement kullanmayın (`;` ile zincirleme).
- DDL/DCL tipindeki komutlar uygulama sorgu katmanında kullanılmamalı.

## 3) Streaming ile Büyük Veri İşleme

Milyonlarca satırda `toList()` yerine `stream()` kullanın.

```php
foreach ($context->set(User::class)->where(fn($u) => $u->IsActive)->stream(2000) as $user) {
    // satır bazlı işleme
}
```

### Ne zaman `stream()`?

- Export süreçleri
- ETL / veri taşıma
- Arka plan batch işlemleri
- Bellek sınırı kritik olan raporlama işleri

### `chunk()` ne durumda?

- `chunk()` geriye uyumluluk için korunur.
- Yeni geliştirmelerde büyük veri için birincil tercih `stream()` olmalıdır.

## 4) Uygulama Kodunda Önerilen Değişiklikler

1. `toList()` ile tüm satırı RAM'e alan uzun sorguları tespit et.
2. Bu sorguları `stream()` veya en azından `chunk()` modeline taşı.
3. Tüm `fromSqlRaw`, string `where`, `joinRaw` çağrılarını parametreli hale getir.
4. SQL üretimi yapan dinamik alanlarda whitelist yaklaşımı kullan.

## 5) Geçiş Kontrol Listesi

- [ ] PDO DSN bilgileri doğru tanımlandı.
- [ ] Production ortamında `ENTITY_FRAMEWORK_STRICT_SQL_SECURITY=true`.
- [ ] Raw SQL çağrılarının tamamı parametreli.
- [ ] Büyük veri sorguları `stream()` ile güncellendi.
- [ ] Kritik endpoint'lerde performans ölçümü tekrar alındı.
- [ ] Güvenlik logları (audit) gözlemleniyor.

## 6) Performans Test Önerisi

Geçişten sonra en az aşağıdaki benchmark'ı alın:

- 1M+ satırlı bir tablo üzerinde:
  - `toList()` (referans)
  - `chunk(1000)`
  - `stream(2000)`

Takip edilmesi gereken metrikler:

- Peak memory
- Total duration
- Ortalama satır işleme hızı
- DB CPU ve I/O etkisi

## 7) Güvenlik Politikası Önerisi (Kurumsal)

- Strict mode kapatılmasın.
- Raw SQL sadece zorunlu senaryolarda ve code review ile kabul edilsin.
- Kullanıcı girdisi içeren hiçbir SQL parçası string concat ile oluşturulmasın.
- Loglarda kişisel veri maskeleme politikası uygulansın.

## 8) Sık Karşılaşılan Hatalar

### `placeholder/binding count mismatch`
SQL içindeki `?` sayısı ile parametre dizisi uzunluğu farklıdır.

### `blocked SQL pattern detected by strict security`
Sorgu, strict policy tarafından riskli bulunmuştur (ör. çoklu statement veya yasaklı token).

### Bellek artışı devam ediyor
`stream()` yerine halen `toList()` kullanılan akışlar olabilir; sorgu zinciri gözden geçirilmelidir.

## 9) Önerilen Geçiş Stratejisi

1. Önce staging ortamında strict mode ile tüm kritik akışları çalıştır.
2. Hata veren raw SQL çağrılarını parametreli hale getir.
3. Büyük sorguları `stream()`'e taşı.
4. Performans ve güvenlik loglarını doğrula.
5. Sonra production'a al.

---

İstersen bir sonraki adımda, proje içinde bu paketi kullanan yerleri tarayıp sana otomatik bir "geçiş yapılacak dosyalar listesi" de çıkarabilirim.

