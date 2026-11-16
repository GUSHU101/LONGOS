<?php

namespace Plugin\LongosProductReviews\Services;

final class ReviewGenerationLimits
{
    public const MAX_REVIEWS_PER_BATCH = 500;

    public const CHUNK_SIZE = 10;

    private function __construct() {}
}
