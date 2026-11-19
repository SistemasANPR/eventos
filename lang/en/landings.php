<?php

declare(strict_types=1);

/*
 * Event landing texts (plan 022): block validation errors, editor messages and panel labels.
 * The fixed labels of the public page live in `public.php`.
 */
return [
    'errors' => [
        'invalid' => 'The value is not valid.',
        'required' => 'This field is required.',
        'unknown_field' => 'The block does not accept this field.',
        'locale_not_offered' => 'The event does not offer this language.',
        'too_long' => 'It cannot exceed :max characters.',
        'too_many' => 'It cannot have more than :max items.',
        'too_many_blocks' => 'The landing cannot have more than :max blocks.',
        'too_many_images' => 'The landing cannot use more than :max images.',
        'too_large' => 'The landing content cannot exceed :max KB.',
        'block_type' => 'The block type is not valid.',
        'url_https' => 'The link must be an external https address.',
        'image_not_found' => 'The image does not exist in this landing.',
        'image_limit' => 'The landing already has :max images; remove one before uploading another.',
        'image_in_use' => 'The image is in the published version; remove it from the blocks and update first.',
        'nothing_to_discard' => 'The landing has no published version yet: there is nothing to go back to.',
        'version_conflict' => 'The landing changed since you opened it. Reload the editor to see the latest version.',
    ],
    'editor' => [
        'navigation' => 'Design landing',
        'title' => 'Landing of :name',
        'saved' => 'Draft saved.',
        'published' => 'Landing updated.',
        'discarded' => 'Changes discarded.',
        'image_uploaded' => 'Image uploaded.',
        'failed' => 'The landing could not be saved.',
        'unavailable' => 'The editor is not available in this build.',
    ],
];
