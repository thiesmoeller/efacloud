<?php

declare(strict_types=1);

/** Read-only source for efaStats. */
final class Stats_repository
{
    /** @var array<int,array<string,mixed>> */
    private array $trips;
    /** @var array<int,array<string,mixed>> */
    private array $boats;
    /** @var array<int,array<string,mixed>> */
    private array $destinations;
    /** @var array<int,array<string,mixed>> */
    private array $persons;

    public function __construct(array $trips, array $boats, array $destinations, array $persons)
    {
        $this->trips = $trips;
        $this->boats = $boats;
        $this->destinations = $destinations;
        $this->persons = $persons;
    }

    public static function from_mysqli(mysqli $db): self
    {
        $crew = [];
        for ($i = 1; $i <= 24; $i++) {
            $crew[] = "Crew{$i}Id";
        }
        $tripColumns = array_merge([
            'EntryId', 'Logbookname', 'Date', 'EndDate', 'StartTime', 'EndTime',
            'BoatId', 'BoatName', 'BoatVariant', 'CoxId', 'DestinationId',
            'DestinationName', 'Distance', 'SessionType', 'Open', 'LastModified',
            'LastModification',
        ], $crew);

        return new self(
            self::query($db, 'SELECT `' . implode('`,`', $tripColumns) . '` FROM efa2logbook'),
            self::query($db, 'SELECT Id,Name,TypeVariant,TypeDescription,TypeType,TypeSeats,TypeRigging,TypeCoxing,ExcludeFromStatistics,ValidFrom,InvalidFrom,Deleted,LastModification FROM efa2boats'),
            self::query($db, 'SELECT Id,Name,ValidFrom,InvalidFrom,Deleted,LastModification FROM efa2destinations'),
            self::query($db, 'SELECT Id,ExcludeFromStatistics,ValidFrom,InvalidFrom,Deleted,LastModification FROM efa2persons')
        );
    }

    public static function from_fixture_dir(string $dir): self
    {
        $read = static function (string $name) use ($dir): array {
            $value = json_decode((string) file_get_contents($dir . '/' . $name), true);
            return is_array($value) ? $value : [];
        };
        return new self(
            $read('logbook-2026.json'),
            $read('boats.json'),
            $read('destinations.json'),
            $read('persons.json')
        );
    }

    private static function query(mysqli $db, string $sql): array
    {
        $result = $db->query($sql);
        if ($result === false) {
            throw new RuntimeException('efaStats database query failed.');
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function service(): Stats_service
    {
        return new Stats_service($this->trips, $this->boats, $this->destinations, $this->persons);
    }
}
