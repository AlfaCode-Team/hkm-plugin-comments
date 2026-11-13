<?php

declare(strict_types=1);

/**
 * English copy for the Comments plugin's user-facing messages.
 *
 * Same boundary rule as the sibling Posts/Feedback plugins: Domain value
 * objects (`AuthorEmail`, `AuthorUrl`, `CommentBody`) throw plain English
 * `\DomainException`s — they may not reach outside Domain/, so they cannot
 * translate their own message. The SERVICE is the boundary where a domain
 * failure becomes a 422 the user reads, so it asks for the catalogue line,
 * passing the domain's own English message as the fallback.
 */
return [
    'post' => [
        'not_accepting' => 'This post is not accepting comments.',
    ],

    'author_name' => [
        'required' => 'Please enter your name.',
        'too_long' => 'Your name is too long.',
    ],

    'author_email' => [
        'invalid' => 'Please provide a valid email address.',
    ],

    'author_url' => [
        'invalid' => 'The website URL must start with http:// or https://.',
    ],

    'comment_body' => [
        'invalid' => 'Please check your comment and try again.',
    ],

    'form_token' => [
        'expired'   => 'This form has expired. Please reload the page and try again.',
        'too_fast'  => 'Please take a moment before submitting.',
    ],

    'parent' => [
        'not_found' => 'That comment could not be found.',
    ],

    'body' => [
        'duplicate' => 'You have already submitted this comment.',
    ],

    'status' => [
        'invalid' => 'That is not a valid comment status.',
    ],

    'moderate' => [
        'unauthenticated' => 'You must sign in to moderate comments.',
        'forbidden'       => 'You do not have permission to moderate comments.',
    ],

    'throttle' => [
        'too_many' => 'Too many comments. Please try again in a minute.',
    ],

    'not_found' => 'Comment [:id] was not found.',
];
