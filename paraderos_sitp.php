<?php

// ---------------------------------------------------------------------------
// BLOQUE 1: Configuracion general y catalogo de localidades
// ---------------------------------------------------------------------------

// Mostramos errores en desarrollo pero los capturamos nosotros mismos para
// nunca imprimir un error crudo de PHP al usuario final
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Endpoint REST tipo ArcGIS FeatureServer, publicado por IDECA / Datos
// Abiertos Bogota. No requiere autenticacion. Devuelve JSON (o GeoJSON).
define('SITP_ENDPOINT',
    'https://services2.arcgis.com/NEwhEo9GGSHXcRXV/arcgis/rest/services/' .
    'Paraderos_SITP_Bogot%C3%A1_D_C/FeatureServer/0/query'
);

// Radio de busqueda alrededor del centro de la localidad (en metros).
define('RADIO_METROS', 1500);

// Catalogo con el centro aproximado (lat, lon) de cada localidad de Bogota.
// Se usa para la consulta espacial descrita arriba y tambien para llenar
// el <select> del formulario asi validamos que el usuario solo pueda
// escoger una localidad que realmente existe
$localidades = [
    'Usaquen'            => [4.6946, -74.0307],
    'Chapinero'          => [4.6488, -74.0648],
    'Santa Fe'           => [4.6097, -74.0817],
    'San Cristobal'      => [4.5709, -74.0817],
    'Usme'               => [4.4808, -74.1260],
    'Tunjuelito'         => [4.5726, -74.1330],
    'Bosa'               => [4.6188, -74.1770],
    'Kennedy'            => [4.6280, -74.1567],
    'Fontibon'           => [4.6740, -74.1460],
    'Engativa'           => [4.7100, -74.1110],
    'Suba'               => [4.7420, -74.0830],
    'Barrios Unidos'     => [4.6670, -74.0840],
    'Teusaquillo'        => [4.6370, -74.0930],
    'Los Martires'       => [4.6050, -74.0910],
    'Antonio Nariño'     => [4.5930, -74.1000],
    'Puente Aranda'      => [4.6150, -74.1160],
    'La Candelaria'      => [4.5967, -74.0750],
    'Rafael Uribe Uribe' => [4.5580, -74.1130],
    'Ciudad Bolivar'     => [4.4941, -74.1430],
];

// ---------------------------------------------------------------------------
// BLOQUE 2: Validacion basica del dato ingresado por el usuario
// ---------------------------------------------------------------------------

$localidadSeleccionada = '';
$paraderos              = [];
$mensajeError           = '';
$consultaRealizada      = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // trim() quita espacios en blanco accidentales.
    $localidadSeleccionada = trim($_POST['localidad'] ?? '');

    // Validamos que el valor recibido exista realmente en nuestro catalogo.
    // Esto evita construir una consulta con datos "raros" o maliciosos y
    // cubre el requisito de si el nombre de localidad no existe, mostrar
    // un mensaje amigable.
    if ($localidadSeleccionada === '') {
        $mensajeError = 'Debes seleccionar una localidad.';
    } elseif (!array_key_exists($localidadSeleccionada, $localidades)) {
        $mensajeError = 'La localidad ingresada no existe en el listado de Bogota.';
    } else {
        $consultaRealizada = true;
    }
}

// ---------------------------------------------------------------------------
// BLOQUE 3: Consumo de la API (file_get_contents con contexto HTTP)
// ---------------------------------------------------------------------------

/**
 * Consulta el servicio REST de paraderos SITP alrededor de un punto.
 *
 * @param float $lat  Latitud del centro de busqueda.
 * @param float $lon  Longitud del centro de busqueda.
 * @return array{ok:bool, data:array, error:string} Resultado normalizado.
 */
function consultarParaderosSitp(float $lat, float $lon): array
{
    // Parametros de la consulta REST tipo ArcGIS:
    // - geometry / geometryType: el punto central de la localidad.
    // - distance / units: radio de busqueda en metros.
    // - spatialRel: que la geometria del paradero "intersecte" el circulo.
    // - outFields=*: traer todos los campos disponibles.
    // - f=json: formato de respuesta.
    $parametros = [
        'where'         => '1=1',
        'geometry'      => $lon . ',' . $lat, // ArcGIS espera "x,y" = lon,lat
        'geometryType'  => 'esriGeometryPoint',
        'inSR'          => 4326,
        'spatialRel'    => 'esriSpatialRelIntersects',
        'distance'      => RADIO_METROS,
        'units'         => 'esriSRUnit_Meter',
        'outFields'     => '*',
        'outSR'         => 4326,
        'resultRecordCount' => 200,
        'f'             => 'json',
    ];

    $url = SITP_ENDPOINT . '?' . http_build_query($parametros);

    // Contexto HTTP: definimos un timeout para no dejar la pagina "colgada"
    // si el servicio de IDECA esta lento o caido
    $contexto = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 8, // segundos
            'header'  => "User-Agent: ActividadPHP-SITP/1.0\r\n",
        ],
    ]);

    // @ suprime el warning nativo de PHP; el error real lo controlamos
    // nosotros abajo revisando si $respuesta vino en false
    $respuesta = @file_get_contents($url, false, $contexto);

    if ($respuesta === false) {
        return ['ok' => false, 'data' => [], 'error' => 'No fue posible conectarse al servicio de la Alcaldia (API SITP). Intenta de nuevo mas tarde.'];
    }

    // Decodificamos el JSON devuelto por la API.
    $json = json_decode($respuesta, true);

    if ($json === null) {
        return ['ok' => false, 'data' => [], 'error' => 'La API respondio en un formato inesperado (no era JSON valido).'];
    }

    // El servicio ArcGIS devuelve errores dentro del propio JSON (no con un
    // codigo HTTP), por eso los revisamos aparte.
    if (isset($json['error'])) {
        $detalle = $json['error']['message'] ?? 'Error desconocido de la API.';
        return ['ok' => false, 'data' => [], 'error' => 'La API respondio con un error: ' . $detalle];
    }

    if (!isset($json['features']) || !is_array($json['features'])) {
        return ['ok' => false, 'data' => [], 'error' => 'La respuesta de la API no trae resultados utilizables.'];
    }

    return ['ok' => true, 'data' => $json['features'], 'error' => ''];
}

// Si el formulario fue enviado con una localidad valida, consultamos la API.
if ($consultaRealizada) {
    [$lat, $lon] = $localidades[$localidadSeleccionada];
    $resultado = consultarParaderosSitp($lat, $lon);

    if (!$resultado['ok']) {
        $mensajeError = $resultado['error'];
    } else {
        // -----------------------------------------------------------------
        // BLOQUE 4: Procesamiento de la respuesta -> recorrido con foreach
        // -----------------------------------------------------------------
        foreach ($resultado['data'] as $feature) {
            $atributos = $feature['attributes'] ?? [];
            $geometria = $feature['geometry']   ?? [];

            $paraderos[] = [
                'codigo'    => $atributos['NTRCODIGO']    ?? 'N/D',
                'nombre'    => $atributos['NTRNOMBRE']    ?? 'Sin nombre registrado',
                'direccion' => $atributos['NTRDIRECCION'] ?? 'Sin direccion registrada',
                'lat'       => $geometria['y'] ?? null,
                'lon'       => $geometria['x'] ?? null,
            ];
        }

        if (empty($paraderos)) {
            $mensajeError = 'La API respondio correctamente, pero no se encontraron paraderos '
                          . 'cerca de "' . htmlspecialchars($localidadSeleccionada) . '".';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Paraderos SITP por localidad - Bogota</title>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #f4f6f8;
        color: #222;
        margin: 0;
        padding: 30px;
    }
    .contenedor {
        max-width: 900px;
        margin: 0 auto;
        background: #fff;
        padding: 25px 30px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    h1 {
        color: #1a4d8f;
        font-size: 1.6em;
        margin-top: 0;
    }
    p.subtitulo {
        color: #555;
        margin-top: -8px;
    }
    form {
        display: flex;
        gap: 10px;
        align-items: center;
        margin: 20px 0;
        flex-wrap: wrap;
    }
    select, button {
        padding: 8px 12px;
        font-size: 1em;
        border-radius: 5px;
        border: 1px solid #ccc;
    }
    button {
        background: #1a4d8f;
        color: #fff;
        border: none;
        cursor: pointer;
    }
    button:hover {
        background: #123a6b;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    th, td {
        padding: 8px 10px;
        border-bottom: 1px solid #e0e0e0;
        text-align: left;
        font-size: 0.92em;
    }
    th {
        background: #eaf1fb;
        color: #1a4d8f;
    }
    tr:hover {
        background: #f7fbff;
    }
    .aviso {
        background: #fff3cd;
        border: 1px solid #ffe08a;
        color: #6b5300;
        padding: 12px 15px;
        border-radius: 6px;
        margin-top: 15px;
    }
    .resumen {
        color: #444;
        margin-top: 15px;
    }
    footer {
        margin-top: 25px;
        font-size: 0.8em;
        color: #888;
    }
</style>
</head>
<body>
<div class="contenedor">

    <h1>Paraderos del SITP por localidad</h1>
    <p class="subtitulo">Consulta el servicio REST publico de IDECA / Datos Abiertos Bogota.</p>

    <!-- ============================================================
         BLOQUE 5: Formulario HTML (select con las localidades validas)
         ============================================================ -->
    <form method="POST" action="paraderos_sitp.php">
        <label for="localidad">Localidad:</label>
        <select name="localidad" id="localidad">
            <option value="">-- Selecciona una localidad --</option>
            <?php foreach ($localidades as $nombre => $coords): ?>
                <option value="<?= htmlspecialchars($nombre) ?>"
                    <?= ($nombre === $localidadSeleccionada) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($nombre) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Buscar paraderos</button>
    </form>

    <?php if ($mensajeError !== ''): ?>
        <!-- Mensaje de error amigable: nunca se muestra un error crudo de PHP -->
        <div class="aviso"><?= htmlspecialchars($mensajeError) ?></div>
    <?php endif; ?>

    <?php if (!empty($paraderos)): ?>
        <p class="resumen">
            Se encontraron <strong><?= count($paraderos) ?></strong> paradero(s)
            cerca de <strong><?= htmlspecialchars($localidadSeleccionada) ?></strong>
            (radio de busqueda: <?= RADIO_METROS ?> m).
        </p>

        <!-- ========================================================
             BLOQUE 6: Presentacion de resultados en tabla HTML
             ======================================================== -->
        <table>
            <thead>
                <tr>
                    <th>Codigo</th>
                    <th>Nombre / ubicacion aproximada</th>
                    <th>Direccion</th>
                    <th>Latitud</th>
                    <th>Longitud</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paraderos as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['codigo']) ?></td>
                        <td><?= htmlspecialchars($p['nombre']) ?></td>
                        <td><?= htmlspecialchars($p['direccion']) ?></td>
                        <td><?= $p['lat'] !== null ? htmlspecialchars(round($p['lat'], 6)) : 'N/D' ?></td>
                        <td><?= $p['lon'] !== null ? htmlspecialchars(round($p['lon'], 6)) : 'N/D' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <footer>
        Fuente de datos: Secretaria Distrital de Movilidad / IDECA - Datos Abiertos Bogota.
        Servicio REST (ArcGIS FeatureServer) consumido con file_get_contents() en PHP puro.
    </footer>
</div>
</body>
</html>
