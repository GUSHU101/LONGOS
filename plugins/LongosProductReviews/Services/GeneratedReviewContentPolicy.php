<?php

namespace Plugin\LongosProductReviews\Services;

class GeneratedReviewContentPolicy
{
    public const VIOLATION_META = 'meta';

    public const VIOLATION_VERIFICATION = 'verification';

    public const VIOLATION_CONTACT = 'contact';

    public const VIOLATION_FORMATTING = 'formatting';

    public function violation(string $author, string $title, string $body): ?string
    {
        $raw = trim($author . "\n" . $title . "\n" . $body);
        if ($this->containsFormatting($raw)) {
            return self::VIOLATION_FORMATTING;
        }

        $content = $this->normalize($raw);
        $compact = preg_replace('/[\p{P}\p{Z}\s_]+/u', '', $content) ?? $content;
        if ($this->matchesAny($content, $this->metaPatterns())
            || $this->matchesAny($compact, $this->compactMetaPatterns())) {
            return self::VIOLATION_META;
        }
        if ($this->matchesAny($content, $this->verificationPatterns())) {
            return self::VIOLATION_VERIFICATION;
        }
        if (preg_match('/(?:https?:\/\/|www\.|[\w.+-]+@[\w.-]+\.[a-z]{2,})/iu', $content)
            || preg_match('/(?<!\d)(?:\+?\d[\s().-]*){8,}(?!\d)/u', $content)) {
            return self::VIOLATION_CONTACT;
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\p{Cf}+/u', '', $value) ?? $value;
        if (function_exists('mb_convert_kana')) {
            $value = mb_convert_kana($value, 'as', 'UTF-8');
        }
        $value = mb_strtolower($value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function containsFormatting(string $value): bool
    {
        return (bool) preg_match('/<\/?[a-z][^>]*>/iu', $value)
            || (bool) preg_match('/```|`[^`]+`|!?\[[^\]]+\]\([^\)]+\)|(?:^|\R)\s{0,3}#{1,6}\s|(?:^|\R)\s*[-*+]\s+|\*\*[^*]+\*\*|__[^_]+__/u', $value);
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function metaPatterns(): array
    {
        return [
            '/\b(?:(?:ai|artificial intelligence|machine|bot)[\s-]*(?:generated|written|created|produced|authored|assisted)|(?:generated|written|created|produced|authored)\s+by\s+(?:an?\s+)?(?:ai|artificial intelligence|bot)|as\s+an?\s+(?:ai|language model|artificial intelligence)|i\s+am\s+an?\s+(?:ai|language model))\b/iu',
            '/\b(?:ai|artificial intelligence|machine|bot)[\s-]*(?:review|reviewer|content|copy)|\b(?:source|origin|attribution|disclosure)\s*[:：-]\s*(?:ai|artificial intelligence|merchant|seller|store|brand|generated|synthetic)/iu',
            '/\b(?:(?:merchant|seller|store|brand)[\s-]+(?:provided|supplied|submitted|generated|written|created|produced|authored|customi[sz]ed|curated|edited)|(?:provided|supplied|submitted|generated|written|created|produced|authored|customi[sz]ed|curated|edited)\s+by\s+(?:the\s+)?(?:merchant|seller|store|brand))(?:\s+(?:content|review))?\b/iu',
            '/\b(?:merchant|seller|store|brand)[\s-]*(?:review|content|copy)\b/iu',
            '/\b(?:sample|example|placeholder|fictional|synthetic|promotional|sponsored|paid)\s+(?:content|review)|\b(?:content|review)\s+(?:draft|sample|placeholder)|\bdraft\s+(?:content|review)|\b(?:advertisement|advertorial)\b/iu',
            '/(?:由)?(?:ai|人工智能|机器人|机器)(?:所)?(?:生成|撰写|创作|制作|编写|编辑)|作为(?:ai|人工智能|机器人|语言模型)|(?:ai|人工智能|机器人|机器)(?:评论|评价|内容|文案)|(?:来源|出处|归属|标注)\s*[:：-]?\s*(?:ai|人工智能|商家|卖家|店铺|品牌|生成)|(?:商家|卖家|店铺|品牌)(?:提供|生成|撰写|创作|制作|定制|自定义|编写|编辑)(?:的)?(?:内容|评论|评价)?|(?:商家|卖家|店铺|品牌)(?:内容|评论|评价|文案)|(?:示例|样例|占位|虚构|合成|推广|赞助|广告|营销|有偿)(?:内容|评论|评价)|(?:评论|评价)草稿|草稿(?:评论|评价)/iu',
            '/(?:ai|人工知能)(?:が|で|によって)?(?:生成|作成|執筆)|(?:販売者|店舗|ブランド)(?:が|によって)?(?:提供|作成|執筆)|(?:サンプル|架空|合成|宣伝|スポンサー)(?:レビュー|口コミ)/iu',
            '/(?:(?:von\s+)?ki[\s-]*(?:generiert(?:e[rsnm]?)?|erstellt(?:e[rsnm]?)?|geschrieben)|(?:händler|verkäufer)[\s-]*(?:bereitgestellt|erstellt|geschrieben)|(?:beispiel|fiktive|synthetische|gesponserte)[\s-]*(?:bewertung|rezension))/iu',
            '/(?:(?:généré|écrit|créé)e?\s+par\s+(?:une?\s+)?ia|(?:fourni|écrit|généré)e?\s+par\s+(?:le\s+)?(?:marchand|vendeur)|avis\s+(?:fictif|synthétique|exemple|promotionnel|sponsorisé))/iu',
            '/(?:(?:generad[oa]|escrit[oa]|cread[oa])\s+por\s+(?:una?\s+)?ia|(?:proporcionad[oa]|generad[oa]|escrit[oa])\s+por\s+(?:el\s+)?(?:vendedor|comerciante)|reseña\s+(?:ficticia|sintética|de\s+ejemplo|promocional|patrocinada))/iu',
        ];
    }

    /** @return list<string> */
    private function compactMetaPatterns(): array
    {
        return [
            '/(?:aigenerated|aiwritten|aicreated|aiproduced|aiauthored|aiassisted|generatedbyai|writtenbyai|createdbyai|artificialintelligencegenerated|machinegenerated|botgenerated|asanai|asailanguagemodel)/iu',
            '/(?:(?:merchant|seller|store|brand)(?:provided|supplied|submitted|generated|written|created|produced|authored|customized|customised|curated|edited)|(?:provided|generated|written|created)by(?:the)?(?:merchant|seller|store|brand))/iu',
            '/(?:samplereview|examplereview|placeholderreview|fictionalreview|syntheticreview|promotionalreview|sponsoredreview|paidreview|reviewdraft)/iu',
            '/(?:ai|人工智能|机器人|机器)(?:生成|撰写|创作|制作|编写|编辑)|作为(?:ai|人工智能|机器人|语言模型)|(?:商家|卖家|店铺|品牌)(?:提供|生成|撰写|创作|制作|定制|自定义|编写|编辑)|(?:示例|样例|占位|虚构|合成|推广|赞助|广告|营销|有偿)(?:内容|评论|评价)/iu',
        ];
    }

    /** @return list<string> */
    private function verificationPatterns(): array
    {
        return [
            '/verified[\s-]+(?:purchase|buyer)/iu',
            '/已验证购买|認證購買|认证购买|確認済みの購入|認証済み購入/iu',
            '/verifiziert(?:er|e|en)?[\s-]+(?:kauf|käufer)/iu',
            '/achat[\s-]+vérifié|acheteur[\s-]+vérifié/iu',
            '/compra[\s-]+verificada|comprador[\s-]+verificado/iu',
        ];
    }
}
