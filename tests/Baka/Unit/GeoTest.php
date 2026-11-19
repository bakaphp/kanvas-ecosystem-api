<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    private const SQUARE = [
        ['lat' => 0, 'lng' => 0],
        ['lat' => 0, 'lng' => 1],
        ['lat' => 1, 'lng' => 1],
        ['lat' => 1, 'lng' => 0],
    ];

    public function testOneDegreeOfLatitudeIsAbout111Km(): void
    {
        $this->assertEqualsWithDelta(111.195, Geo::distanceKm(0.0, 0.0, 1.0, 0.0), 0.01);
    }

    public function testTheSamePointIsZeroKmAway(): void
    {
        $this->assertSame(0.0, Geo::distanceKm(13.6929, -89.2182, 13.6929, -89.2182));
    }

    public function testDistanceIsSymmetric(): void
    {
        $this->assertEqualsWithDelta(
            Geo::distanceKm(13.6929, -89.2182, 13.9942, -89.5597),
            Geo::distanceKm(13.9942, -89.5597, 13.6929, -89.2182),
            1e-9,
        );
    }

    public function testAPointInsideThePolygonIsContained(): void
    {
        $this->assertTrue(Geo::polygonContains(self::SQUARE, 0.5, 0.5));
    }

    public function testAPointOutsideThePolygonIsNotContained(): void
    {
        $this->assertFalse(Geo::polygonContains(self::SQUARE, 1.5, 0.5));
        $this->assertFalse(Geo::polygonContains(self::SQUARE, 0.5, -0.5));
    }

    public function testFewerThanThreeVerticesContainNothing(): void
    {
        $this->assertFalse(Geo::polygonContains([['lat' => 0, 'lng' => 0], ['lat' => 1, 'lng' => 1]], 0.5, 0.5));
    }

    public function testStringCoordinatesAreAccepted(): void
    {
        $polygon = array_map(
            fn (array $vertex) => ['lat' => (string) $vertex['lat'], 'lng' => (string) $vertex['lng']],
            self::SQUARE,
        );

        $this->assertTrue(Geo::polygonContains($polygon, 0.5, 0.5));
    }
}
