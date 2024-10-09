<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Tests\TestCase;

class EventSupportTest extends TestCase
{
    public function testGetEventCategory(): void
    {
        $this->graphQL('
              query {
                  eventCategories {
                      data {
                          id,
                          name
                      }
                  }
              }')->assertSee('eventCategories');
    }

    public function testGetEventType(): void
    {
        $this->graphQL('
              query {
                  eventTypes {
                      data {
                          id,
                          name
                      }
                  }
              }')->assertSee('eventTypes');
    }

    public function testGetEventDate(): void
    {
        $this->graphQL('
              query {
                  eventDates {
                      data {
                          id,
                          date,
                          startTime,
                          endTime
                      }
                  }
              }')->assertSee('eventDates');
    }

    public function testGetEventStatus(): void
    {
        $this->graphQL('
                  query {
                      eventStatus {
                          data {
                              id,
                              name
                          }
                      }
                  }')->assertSee('eventStatus');
    }

    public function testGetEventClass(): void
    {
        $this->graphQL('
                  query {
                      eventClasses {
                          data {
                              id,
                              name
                          }
                      }
                  }')->assertSee('eventClasses');
    }

    public function testEventTheme(): void
    {
        $this->graphQL('
                      query {
                          eventThemes {
                              data {
                                  id,
                                  name
                              }
                          }
                      }')->assertSee('eventThemes');
    }

    public function testEventThemeArea(): void
    {
        $this->graphQL('
                      query {
                          eventThemeAreas {
                              data {
                                  id,
                                  name
                              }
                          }
                      }')->assertSee('eventThemeAreas');
    }
}
