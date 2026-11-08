<?php

namespace App\Models;

use App\Support\SiteCustomization;
use Database\Factories\ProductConcernFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A health concern tag the owner puts on products, such as "Digestion".
 */
class ProductConcern extends Model
{
    /** @use HasFactory<ProductConcernFactory> */
    use HasFactory;

    public const PublicOptionsCacheKey = 'public:product_concerns:v1';

    /** Pivot table linking tags to products. */
    public const PivotTable = 'product_concern_product';

    /** Validation rule that refuses tag names saying a product cures or treats something. */
    public const NoCureWordingRule = 'not_regex:/\b(?:cur(?:e[sd]?|ing)|treat(?:s|ed|ing|ments?)?|heal(?:s|ed|ing)?|remed(?:y|ies)|guarantee[sd]?)\b|নিরাম(?:\x{09DF}|\x{09AF}\x{09BC})|আরোগ্য|চিকিৎসা|চিকিত্সা|রোগ\s*মুক্ত|সারা(?:\x{09DF}|\x{09AF}\x{09BC})|সারানো|সেরে|গ্যারান্টি|শতভাগ|১০০\s*%|100\s*%/iu';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        $bust = static fn () => SiteCustomization::forgetCache();
        static::saved($bust);
        static::deleted($bust);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, self::PivotTable);
    }

    /** The tag's name for the visitor's language. */
    public static function nameForVisitor(string $nameBn, string $nameEn): string
    {
        return app()->getLocale() === 'bn' ? $nameBn : $nameEn;
    }
}
