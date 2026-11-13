<?php

declare(strict_types=1);

/** French copy for the Comments plugin's user-facing messages. */
return [
    'post' => [
        'not_accepting' => 'Cet article n\'accepte pas les commentaires.',
    ],

    'author_name' => [
        'required' => 'Veuillez saisir votre nom.',
        'too_long' => 'Votre nom est trop long.',
    ],

    'author_email' => [
        'invalid' => 'Veuillez fournir une adresse e-mail valide.',
    ],

    'author_url' => [
        'invalid' => 'L\'URL du site doit commencer par http:// ou https://.',
    ],

    'comment_body' => [
        'invalid' => 'Veuillez vérifier votre commentaire et réessayer.',
    ],

    'form_token' => [
        'expired'  => 'Ce formulaire a expiré. Veuillez recharger la page et réessayer.',
        'too_fast' => 'Veuillez prendre un instant avant de soumettre.',
    ],

    'parent' => [
        'not_found' => 'Ce commentaire est introuvable.',
    ],

    'body' => [
        'duplicate' => 'Vous avez déjà soumis ce commentaire.',
    ],

    'status' => [
        'invalid' => 'Ce statut de commentaire n\'est pas valide.',
    ],

    'moderate' => [
        'unauthenticated' => 'Vous devez vous connecter pour modérer les commentaires.',
        'forbidden'       => 'Vous n\'avez pas la permission de modérer les commentaires.',
    ],

    'throttle' => [
        'too_many' => 'Trop de commentaires. Veuillez réessayer dans une minute.',
    ],

    'not_found' => 'Commentaire [:id] introuvable.',
];
