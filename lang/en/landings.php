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
        'call_title' => 'Landing of :call · :name',
        'saved' => 'Draft saved.',
        'published' => 'Landing updated.',
        'discarded' => 'Changes discarded.',
        'image_uploaded' => 'Image uploaded.',
        'failed' => 'The landing could not be saved.',
        'unavailable' => 'The editor is not available in this build.',
    ],
    // Placeholder texts of the call landing template (plan 033): generic, no invented data.
    'call_template' => [
        'axes_title' => 'Topics',
        'axes_intro' => 'Your proposal must fit one of the topics of this edition. In the form you choose the one that describes it best.',
        'requirements_title' => 'Prepare your proposal',
        'requirements_intro' => 'Have this information ready before opening the form, so you can complete it in one sitting.',
        'requirement_one' => 'Gather the information the form asks for.',
        'requirement_two' => 'Complete the online form before the closing date.',
        'requirements_button' => 'Open the form',
        'steps_title' => 'How it works',
        'step_prepare_title' => 'Prepare',
        'step_prepare_text' => 'Gather the information the form asks for.',
        'step_send_title' => 'Submit',
        'step_send_text' => 'Complete the online form before the closing date.',
        'step_review_title' => 'Review',
        'step_review_text' => 'The proposals received will be reviewed by the evaluation committee.',
        'step_results_title' => 'Results',
        'step_results_text' => 'We will email you the result.',
        'step_present_title' => 'Present',
        'step_present_text' => 'Selected proposals become part of the event program.',
        'faq_title' => 'Frequently asked questions',
        'faq_one_question' => 'Where do I send my proposal?',
        'faq_one_answer' => 'With the “Submit proposal” button, while the call is open.',
        'faq_two_question' => 'When will I know the result?',
        'faq_two_answer' => 'We will email you when the review is finished.',
        'promo_button' => 'View call',
    ],
    // Lines the call landing builds by itself from the real data (plan 033).
    'call_live' => [
        'team' => 'Take part **individually or as a team**, with a maximum of **:max people** in total.',
        'individual' => 'The proposal is **individual**.',
        'minimum' => 'Each proposal needs at least **:min people**.',
        'one_axis' => 'Each proposal is registered under **a single topic**: choose the one that matches its central theme.',
        'form_title' => 'What the form asks for',
        'form_required' => '* required',
        'form_proposal' => 'Proposal information',
        'form_participant' => 'Information about each participant',
        'promo_fallback' => 'You can also take part in another call of this event.',
    ],
];
