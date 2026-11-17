<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Enums;

/**
 * External-id keys written to `apps_custom_fields`. The (model_name, name) pair is what makes a
 * key unique, so the same case is reused across models — BRUSHCRAZY_USER_ID lives on People,
 * Participant and Facilitator without colliding.
 */
enum CustomFieldEnum: string
{
    case BRUSHCRAZY_STUDIO_ID = 'BRUSHCRAZY_STUDIO_ID';
    case BRUSHCRAZY_USER_ID = 'BRUSHCRAZY_USER_ID';
    case BRUSHCRAZY_PAINTING_ID = 'BRUSHCRAZY_PAINTING_ID';
    case BRUSHCRAZY_PRODUCT_ID = 'BRUSHCRAZY_PRODUCT_ID';

    /** Slug of name + price in cents — substrates are deduped into shared variants, not 1:1. */
    case BRUSHCRAZY_SUBSTRATE_KEY = 'BRUSHCRAZY_SUBSTRATE_KEY';

    case BRUSHCRAZY_SUBSTRATE_ID = 'BRUSHCRAZY_SUBSTRATE_ID';
    case BRUSHCRAZY_EVENT_GROUP_KEY = 'BRUSHCRAZY_EVENT_GROUP_KEY';

    /**
     * "{morph}:{id}" — lessons.id=5, events.id=5 and workshops.id=5 are three different objects,
     * and this is the exact shape `registrations.calendarable_type`/`calendarable_id` already
     * stores, so joins need no translation.
     */
    case BRUSHCRAZY_CALENDARABLE_KEY = 'BRUSHCRAZY_CALENDARABLE_KEY';

    /** The class's ticket options on its EventVersion; see CalendarableMapper::substrates(). */
    case BRUSHCRAZY_SUBSTRATES = 'BRUSHCRAZY_SUBSTRATES';

    case BRUSHCRAZY_REGISTRATION_ID = 'BRUSHCRAZY_REGISTRATION_ID';
    case BRUSHCRAZY_ATTENDEE_ID = 'BRUSHCRAZY_ATTENDEE_ID';
    case BRUSHCRAZY_SALE_ID = 'BRUSHCRAZY_SALE_ID';
    case BRUSHCRAZY_SALE_ITEM_ID = 'BRUSHCRAZY_SALE_ITEM_ID';
    case BRUSHCRAZY_MEDIA_ID = 'BRUSHCRAZY_MEDIA_ID';
    case BRUSHCRAZY_LOOKUP_KEY = 'BRUSHCRAZY_LOOKUP_KEY';
    case BRUSHCRAZY_SHIFT_ID = 'BRUSHCRAZY_SHIFT_ID';
    case BRUSHCRAZY_UNKNOWN_GUEST = 'BRUSHCRAZY_UNKNOWN_GUEST';

    /**
     * 'kanvas' on rows the site created and pushed to BrushCrazy. The mirror must not rebuild
     * them from the source and the push activities must not push back what came from BrushCrazy.
     */
    case BRUSHCRAZY_ORIGIN = 'BRUSHCRAZY_ORIGIN';

    case BRUSHCRAZY_STUDIO_TIMEZONE = 'BRUSHCRAZY_STUDIO_TIMEZONE';
    case BRUSHCRAZY_STUDIO_OCCUPANCY = 'BRUSHCRAZY_STUDIO_OCCUPANCY';
    case BRUSHCRAZY_STUDIO_SETTINGS = 'BRUSHCRAZY_STUDIO_SETTINGS';
    case BRUSHCRAZY_STUDIO_SALES_TAX = 'BRUSHCRAZY_STUDIO_SALES_TAX';
    case BRUSHCRAZY_STUDIO_BILLING = 'BRUSHCRAZY_STUDIO_BILLING';
    case BRUSHCRAZY_STUDIO_SOCIAL = 'BRUSHCRAZY_STUDIO_SOCIAL';
    case BRUSHCRAZY_STUDIO_LAT = 'BRUSHCRAZY_STUDIO_LAT';
    case BRUSHCRAZY_STUDIO_LNG = 'BRUSHCRAZY_STUDIO_LNG';
    case BRUSHCRAZY_DISPLAY_NAME = 'BRUSHCRAZY_DISPLAY_NAME';
    /** `users.birthday` is a month (1-12) — BrushCrazy only ever compares it with isSameMonth(). */
    case BRUSHCRAZY_BIRTHDAY_MONTH = 'BRUSHCRAZY_BIRTHDAY_MONTH';

    /**
     * The customer's `settings.promotionEmails` flag. Deliberately not mapped onto Contact
     * `is_opt_out`: that is do-not-contact on every channel, while this only covers marketing.
     */
    case BRUSHCRAZY_PROMOTION_EMAILS = 'BRUSHCRAZY_PROMOTION_EMAILS';
    case BRUSHCRAZY_POINTS = 'BRUSHCRAZY_POINTS';
    case BRUSHCRAZY_REWARDS = 'BRUSHCRAZY_REWARDS';

    /** `users.card`, the balance BrushCrazy charges against — not the Σ of its drifting ledger. */
    case BRUSHCRAZY_VIP_CARD_CENTS = 'BRUSHCRAZY_VIP_CARD_CENTS';

    /** Σ `customer_credits` for this user in this company's studio only; credit is studio-scoped. */
    case BRUSHCRAZY_STUDIO_CREDIT_CENTS = 'BRUSHCRAZY_STUDIO_CREDIT_CENTS';
    case BRUSHCRAZY_FAVORITE_STUDIO_ID = 'BRUSHCRAZY_FAVORITE_STUDIO_ID';
    case BRUSHCRAZY_LAST_STUDIO_ID = 'BRUSHCRAZY_LAST_STUDIO_ID';
    case BRUSHCRAZY_ROLES = 'BRUSHCRAZY_ROLES';
    case BRUSHCRAZY_EMPLOYEE_POSITION = 'BRUSHCRAZY_EMPLOYEE_POSITION';
    case BRUSHCRAZY_PAINTING_SUBTITLE = 'BRUSHCRAZY_PAINTING_SUBTITLE';
    case BRUSHCRAZY_PAINTING_DIFFICULTY = 'BRUSHCRAZY_PAINTING_DIFFICULTY';
    case BRUSHCRAZY_PAINTING_DURATION = 'BRUSHCRAZY_PAINTING_DURATION';
    case BRUSHCRAZY_PAINTING_RECIPE = 'BRUSHCRAZY_PAINTING_RECIPE';

    /** BrushCrazy has no description column: it is the `painting_directions` row at the reserved sort order. */
    case BRUSHCRAZY_PAINTING_DESCRIPTION = 'BRUSHCRAZY_PAINTING_DESCRIPTION';
    case BRUSHCRAZY_PAINTING_DIRECTIONS = 'BRUSHCRAZY_PAINTING_DIRECTIONS';
    case BRUSHCRAZY_PAINTING_TAGS = 'BRUSHCRAZY_PAINTING_TAGS';
    case BRUSHCRAZY_PAINTING_PUBLISHED_AT = 'BRUSHCRAZY_PAINTING_PUBLISHED_AT';
    case BRUSHCRAZY_PAINTING_APPROVED_AT = 'BRUSHCRAZY_PAINTING_APPROVED_AT';

    /**
     * Map of `filesystem_entities.field_name` => {media_id, url} for every photo the mirror attached
     * to the entity. Filesystem rows carry no custom fields, so the dedupe key lives on the owner; a
     * photo is re-attached only when its media id changes, and only the link to that url is removed.
     */
    case BRUSHCRAZY_ATTACHED_MEDIA = 'BRUSHCRAZY_ATTACHED_MEDIA';

    /** `studio_employees.employee_id`: a random 4-digit clock-in badge, not a foreign key. */
    case BRUSHCRAZY_EMPLOYEE_BADGE = 'BRUSHCRAZY_EMPLOYEE_BADGE';

    /** Start of the latest stint in this studio; a position or wage change resets the source's `hired_at`. */
    case BRUSHCRAZY_EMPLOYEE_HIRED_AT = 'BRUSHCRAZY_EMPLOYEE_HIRED_AT';
    case BRUSHCRAZY_EMPLOYEE_TERMINATED_AT = 'BRUSHCRAZY_EMPLOYEE_TERMINATED_AT';
    case BRUSHCRAZY_STUDIO_OWNER = 'BRUSHCRAZY_STUDIO_OWNER';

    /** Every `studio_employees` row for this studio, oldest first. No wage: any company user can read it. */
    case BRUSHCRAZY_EMPLOYMENT_HISTORY = 'BRUSHCRAZY_EMPLOYMENT_HISTORY';

    /**
     * "{morph}:{calendarable_id}:u{buyer_id}" (or ":guest") on an EventVersionParticipant — one per
     * buyer per calendarable, merging every registration of that buyer. Stored with
     * companies_id = 0 like every EVP custom field, so it is only ever read back scoped to the
     * event version's own EVP ids, never as a global value lookup.
     */
    case BRUSHCRAZY_PARTICIPATION_KEY = 'BRUSHCRAZY_PARTICIPATION_KEY';

    /**
     * Set when the mirror soft-deleted the row because the source did. Only such a row is restored
     * by a source restore; one deleted in Kanvas stays deleted.
     */
    case BRUSHCRAZY_DELETED_BY_MIRROR = 'BRUSHCRAZY_DELETED_BY_MIRROR';

    /**
     * Whether a Facilitator is current staff of this studio. Former staff and instructors only
     * covering here stay live (is_deleted = 0) so the classes they taught still show them.
     */
    case BRUSHCRAZY_STAFF_ACTIVE = 'BRUSHCRAZY_STAFF_ACTIVE';

    /** ContactTypeEnum value => peoples_contacts.id of the contact the mirror manages for that type. */
    case BRUSHCRAZY_MIRRORED_CONTACTS = 'BRUSHCRAZY_MIRRORED_CONTACTS';
}
