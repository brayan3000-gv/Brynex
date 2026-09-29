<?php

namespace App\Services\Caja;

use Illuminate\Support\Facades\DB;

/**
 * Los datos de contacto que exigen los portales de las cajas: cuando el
 * trabajador no los tiene en BryNex se usan los de su empleador, que es quien
 * hace el trámite y a quien la caja le responde.
 */
trait ContactoDeCaja
{
    /**
     * El primer celular de 10 dígitos entre los valores dados. Cada uno puede
     * traer varios ("3001112233 / 3004445566") y las razones sociales los
     * guardan separados por coma.
     */
    private function celular(?string ...$valores): ?string
    {
        return collect($valores)
            ->flatMap(fn ($t) => preg_split('/[,;\/|]| - /', (string) $t))
            ->map(fn ($t) => preg_replace('/\D/', '', $t))
            ->first(fn ($t) => strlen($t) === 10) ?: null;
    }

    /**
     * El correo propio de la razón social. Primero sus campos; si están vacíos,
     * el buzón que BryNex le abrió, que vive en el módulo de claves como una
     * clave de correo de esa empresa (misma idea que BuzonGmail::delAliado,
     * pero por empresa en vez de por aliado).
     *
     * Se busca por NIT y no por el id de la razón social porque la misma
     * empresa puede estar registrada en varios aliados —LALA Confecciones está
     * en Brygar y en Fecop— y el correo es de la empresa, igual que la clave
     * del portal que resuelve credencial().
     */
    private function correoRazonSocial($rs): ?string
    {
        if ($correo = $this->correo($rs?->correo_formulario, $rs?->correos)) {
            return $correo;
        }

        $nit = preg_replace('/\D/', '', (string) $rs?->nit);
        if (! $nit) {
            return null;
        }

        $campos = DB::table('razones_sociales')
            ->where('nit', $nit)
            ->orderByDesc('id')
            ->get(['correo_formulario', 'correos'])
            ->flatMap(fn ($r) => [$r->correo_formulario, $r->correos])
            ->all();

        $claves = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('rs.nit', $nit)
            ->where('c.activo', true)
            ->where(fn ($q) => $q->where('c.tipo', 'Correo')->orWhere('c.entidad', 'like', '%GMAIL%'))
            ->orderByDesc('c.updated_at')
            ->pluck('c.usuario')
            ->all();

        return $this->correo(...$campos, ...$claves);
    }

    /** El primer correo con forma de correo entre los valores dados. */
    private function correo(?string ...$valores): ?string
    {
        return collect($valores)
            ->flatMap(fn ($c) => preg_split('/[,;\s]+/', (string) $c))
            ->map(fn ($c) => trim($c))
            ->first(fn ($c) => (bool) filter_var($c, FILTER_VALIDATE_EMAIL)) ?: null;
    }
}
