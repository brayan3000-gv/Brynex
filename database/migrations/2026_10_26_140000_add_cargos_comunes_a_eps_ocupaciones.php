<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El resto del catálogo común de cargos (razon_social_cargos, códigos CIUO-88)
 * con su equivalente en el catálogo de Nueva EPS, que mezcla CIUO-08 de cuatro
 * dígitos y códigos propios de seis. Bajado del portal el 26-oct-2026 con el
 * modo `cargos` de scripts/nueva-eps-portal.mjs, y cada código verificado con
 * la misma búsqueda que hace el reingreso.
 *
 * La llave es el código de ocupación, no el cargo: SECRETARIA y ASISTENTE DE
 * GERENCIA comparten el 4113, VENDEDOR DE ALMACEN e IMPULSADOR el 5320, y las
 * versiones "en alturas" el código de la de piso.
 */
return new class extends Migration
{
    private const EQUIVALENCIAS = [
        // BryNex, Nueva EPS, descripción de Nueva EPS
        ['5320', '632103', 'VENDEDOR DE ALMACEN'],
        ['4223', '4222', 'EMPLEADOS DE CENTROS DE LLAMADAS'],
        ['3121', '3513', 'TÉCNICOS EN REDES Y SISTEMAS DE COMPUTACIÓN'],
        ['4113', '3343', 'SECRETARIOS ADMINISTRATIVOS Y EJECUTIVOS'],
        ['4222', '131503', 'RECEPCIONISTA'],
        ['4121', '133103', 'AUXILIAR DE CONTABILIDAD'],
        ['1412', '621104', 'SUPERVISOR DE VENTAS'],
        ['4123', '134105', 'AUXILIAR ADMINISTRATIVO'],
        ['4112', '132102', 'DIGITADOR'],
        ['4141', '511301', 'ARCHIVISTA'],
        ['4211', '632301', 'CAJERO'],
        ['4131', '137202', 'AUXILIAR DE INVENTARIOS'],
        ['4122', '134201', 'AUXILIAR DE NOMINA'],
        ['2411', '111101', 'CONTADOR'],
        ['2413', '2413', 'ANALISTAS FINANCIEROS'],
        ['2419', '112203', 'ANALISTA ADMINISTRATIVO'],
        ['1411', '121102', 'COORDINADOR ADMINISTRATIVO'],
        ['1322', '101201', 'JEFE DE RECURSOS HUMANOS'],
        ['1321', '121103', 'JEFE ADMINISTRATIVO'],
        ['1211', '1120', 'DIRECTORES Y GERENTES GENERALES'],
        ['1212', '1120', 'DIRECTORES Y GERENTES GENERALES'],
        ['1326', '201302', 'GERENTE DE INFORMATICA'],
        ['1323', '601103', 'JEFE DE VENTAS'],
        ['1324', '121301', 'JEFE DE PUBLICIDAD'],
        ['1413', '2431', 'PROFESIONALES DE LA PUBLICIDAD Y LA COMERCIALIZACIÓN'],
        ['3411', '623101', 'AGENTE DE SEGUROS'],
        ['3412', '623204', 'AGENTE INMOBILIARIO'],
        ['3413', '633101', 'AGENTE DE VIAJES'],
        ['3415', '623503', 'COMPRADOR'],
        ['3421', '137204', 'AUXILIAR DE COMPRAS'],
        ['3422', '122703', 'ASISTENTE DE COMERCIO EXTERIOR'],
        ['3429', '3324', 'AGENTES DE OPERACIONES COMERCIALES Y CONSIGNATARIOS'],
        ['5342', '632101', 'TELEMERCADERISTA'],
        ['8267', '7532', 'PATRONISTAS Y CORTADORES DE TELA, CUERO Y AFINES'],
        ['7723', '7531', 'SASTRES, MODISTOS, PELETEROS Y SOMBREREROS'],
        ['5122', '635302', 'MESERO'],
        ['5330', '5211', 'VENDEDORES EN QUIOSCOS Y PUESTOS DE MERCADO'],
        ['5341', '661212', 'VENDEDOR AMBULANTE'],
        ['7422', '843305', 'REPARADOR DE COMPUTADORES Y EQUIPO PERIFERICO'],
        ['9313', '861102', 'OBRERO DE CONSTRUCCION'],
        ['7211', '7112', 'ALBAÑILES'],
        ['7226', '832202', 'ELECTRICISTA DE OBRA'],
        ['7232', '837402', 'PINTOR DE CONSTRUCCION'],
        ['7312', '834203', 'SOLDADOR'],
        ['5121', '635503', 'COCINERO'],
        ['9221', '848106', 'AUXILIAR DE SERVICIOS GENERALES'],
        ['9133', '663104', 'VIGILANTE'],
        ['9131', '136203', 'MENSAJERO'],
        ['8321', '8323', 'CONDUCTORES DE CAMIONETAS Y VEHÍCULOS LIVIANOS'],
    ];

    public function up(): void
    {
        $existentes = DB::table('eps_ocupaciones')->where('entidad', 'nueva_eps')->pluck('codigo_ocupacion')->all();

        foreach (self::EQUIVALENCIAS as [$codigo, $entidad, $descripcion]) {
            if (in_array($codigo, $existentes, true)) {
                continue;
            }

            DB::table('eps_ocupaciones')->insert([
                'entidad'             => 'nueva_eps',
                'codigo_ocupacion'    => $codigo,
                'codigo_entidad'      => $entidad,
                'descripcion_entidad' => $descripcion,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('eps_ocupaciones')->where('entidad', 'nueva_eps')
            ->whereIn('codigo_ocupacion', array_column(self::EQUIVALENCIAS, 0))
            ->delete();
    }
};
