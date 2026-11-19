<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function distanceKm(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(sqrt($a));
    }

    /**
     * Ray casting on raw lat/lng. Planar math is fine at city scale; it breaks only for polygons
     * spanning the antimeridian or a pole, which no geofence of ours does.
     *
     * @param array<int, array{lat: float|int|string, lng: float|int|string}> $polygon
     */
    public static function polygonContains(array $polygon, float $lat, float $lng): bool
    {
        $vertices = array_values($polygon);
        $count = count($vertices);

        if ($count < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = (float) $vertices[$i]['lat'];
            $lngI = (float) $vertices[$i]['lng'];
            $latJ = (float) $vertices[$j]['lat'];
            $lngJ = (float) $vertices[$j]['lng'];

            $crosses = ($latI > $lat) !== ($latJ > $lat)
                && $lng < ($lngJ - $lngI) * ($lat - $latI) / ($latJ - $latI) + $lngI;

            if ($crosses) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
