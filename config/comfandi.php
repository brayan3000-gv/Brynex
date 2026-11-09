<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Usuario gestor único de Comfandi
    |--------------------------------------------------------------------------
    |
    | Se agrega UN usuario gestor (Brayan García) en "Administración de usuarios"
    | de cada empresa de Comfandi. Con eso basta un login + un TOTP para todas:
    | la API lista las empresas del gestor en el token (claim `companies`) y por
    | operación se usa el x-company-id de la que toca. Ver docs/comfandi-api.md.
    |
    | Aquí solo va el DOCUMENTO del gestor (no es secreto): con él se ubica su
    | clave de Comfandi en el módulo de claves (clave_accesos), de donde salen la
    | contraseña y el secreto TOTP. Así la credencial se maneja desde la UI de
    | claves, no desde el .env.
    |
    */
    'gestor_usuario' => env('COMFANDI_GESTOR_USUARIO', '1143944458'),
];
