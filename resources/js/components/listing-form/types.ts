/**
 * The listing-form field shapes both listing types share, plus the
 * type-specific extensions: sale adds the asking price, charter adds the
 * rate block, operating areas, base ports, and crew. Mirrors the wire
 * schema's type conditional — the two never mix.
 */

export type EngineForm = {
    make: string;
    model: string;
    year: number | null;
    type: string;
    drive_type: string;
    power_hp: number | null;
    power_kw: number | null;
    fuel_type: string;
    hours: number | null;
    hours_recorded_at: string;
    location: string;
};

export type GeneratorForm = {
    make: string;
    model: string;
    power_kw: number | null;
    hours: number | null;
    hours_recorded_at: string;
};

export type SpecificationsForm = {
    beam_m: number | null;
    draft_max_m: number | null;
    draft_min_m: number | null;
    lwl_m: number | null;
    lod_m: number | null;
    bridge_clearance_m: number | null;
    gross_tonnage: number | null;
    displacement_kg: number | null;
    fuel_capacity_l: number | null;
    water_capacity_l: number | null;
    holding_tank_l: number | null;
    cruise_speed_kn: number | null;
    max_speed_kn: number | null;
    range_nmi: number | null;
    fuel_consumption_lph: number | null;
    hull_material: string;
    superstructure_material: string;
    deck_material: string;
    hull_shape: string;
    hull_color: string;
    naval_architect: string;
    exterior_designer: string;
    interior_designer: string;
    fuel_type: string;
    flag: string;
    registry_port: string;
    power_or_sail: string;
    category: { name: string; slug: string };
    cabins: number | null;
    sleeps: number | null;
    heads: number | null;
    guests_cruising: number | null;
    guests_entertaining: number | null;
    cabin_config: Record<
        'double' | 'twin' | 'triple' | 'single' | 'convertible',
        number | null
    >;
    berth_config: Record<
        'king' | 'queen' | 'double' | 'twin' | 'single' | 'pullman' | 'bunk',
        number | null
    >;
    crew_accommodation: {
        cabins: number | null;
        berths: number | null;
        layout: string;
    };
    engines: EngineForm[];
    generators: GeneratorForm[];
    tenders: string;
};

export type ComplianceForm = {
    not_for_sale_to_us_residents_in_us_waters: string;
    vat_status: string;
    ce_certified: string;
    mca_compliant: string;
    classification: {
        society: string;
        notation: string;
        next_survey_due: string;
    }[];
};

export type SharedListingFields = {
    name: string;
    summary: string;
    condition: string;
    location_display: string;
    location_city: string;
    location_state: string;
    location_country: string;
    location_marina: string;
    location_lat: number | null;
    location_lon: number | null;
    builder_slug: string | null;
    builder_name: string;
    model_name: string;
    model_slug: string;
    year_built: number | null;
    refit_year: number | null;
    loa_m: number | null;
    hin: string;
    imo: string;
    mmsi: string;
    official_number: string;
    previous_names: string;
    specifications: SpecificationsForm;
    descriptions: { section: string; content: string }[];
    features: {
        category: string;
        name: string;
        slug: string;
        quantity: number | null;
    }[];
    compliance: ComplianceForm;
    videos: MediaLinkForm[];
    tours: MediaLinkForm[];
};

export type MediaLinkForm = {
    url: string;
    caption: string;
};

export type YachtFormFields = SharedListingFields & {
    price_amount: string;
    price_currency: string;
    price_on_application: boolean;
    starting_price: boolean;
};

export type RateForm = {
    season: string;
    rate_type: string;
    amount_min: string;
    amount_max: string;
    currency: string;
    contract_terms: string;
    apa_percent: number | null;
    vat_percent: number | null;
    valid_from: string;
    valid_to: string;
};

export type OperatingAreaForm = {
    name: string;
    slug: string | null;
    season: string;
};

export type CrewMemberForm = {
    role: string;
    name: string;
    nationality: string;
    bio: string;
    photo_url: string;
    tba: boolean;
};

export type CharterYachtFormFields = SharedListingFields & {
    rates: RateForm[];
    operating_areas: OperatingAreaForm[];
    summer_base_port: string;
    winter_base_port: string;
    crew: CrewMemberForm[];
    crew_attested: boolean;
};

/** A section component's form prop: the fields plus Inertia's errors bag. */
export type ListingForm<Fields = SharedListingFields> = Fields & {
    errors: Partial<Record<string, string>>;
};

export function emptyEngine(): EngineForm {
    return {
        make: '',
        model: '',
        year: null,
        type: 'inboard',
        drive_type: '',
        power_hp: null,
        power_kw: null,
        fuel_type: 'diesel',
        hours: null,
        hours_recorded_at: '',
        location: '',
    };
}

export function emptyGenerator(): GeneratorForm {
    return {
        make: '',
        model: '',
        power_kw: null,
        hours: null,
        hours_recorded_at: '',
    };
}

export function emptySpecifications(): SpecificationsForm {
    return {
        beam_m: null,
        draft_max_m: null,
        draft_min_m: null,
        lwl_m: null,
        lod_m: null,
        bridge_clearance_m: null,
        gross_tonnage: null,
        displacement_kg: null,
        fuel_capacity_l: null,
        water_capacity_l: null,
        holding_tank_l: null,
        cruise_speed_kn: null,
        max_speed_kn: null,
        range_nmi: null,
        fuel_consumption_lph: null,
        hull_material: '',
        superstructure_material: '',
        deck_material: '',
        hull_shape: '',
        hull_color: '',
        naval_architect: '',
        exterior_designer: '',
        interior_designer: '',
        fuel_type: '',
        flag: '',
        registry_port: '',
        power_or_sail: 'power',
        category: { name: '', slug: '' },
        cabins: null,
        sleeps: null,
        heads: null,
        guests_cruising: null,
        guests_entertaining: null,
        cabin_config: {
            double: null,
            twin: null,
            triple: null,
            single: null,
            convertible: null,
        },
        berth_config: {
            king: null,
            queen: null,
            double: null,
            twin: null,
            single: null,
            pullman: null,
            bunk: null,
        },
        crew_accommodation: { cabins: null, berths: null, layout: '' },
        engines: [],
        generators: [],
        tenders: '',
    };
}

/**
 * Tri-state selects use an 'unknown' sentinel (reka Select forbids empty
 * string values); the wire wants true/false/null.
 */
export function normalizeCompliance(compliance: ComplianceForm) {
    const triState = (value: string) =>
        value === '1' ? true : value === '0' ? false : null;

    return {
        ...compliance,
        not_for_sale_to_us_residents_in_us_waters: triState(
            compliance.not_for_sale_to_us_residents_in_us_waters,
        ),
        ce_certified: triState(compliance.ce_certified),
        mca_compliant: triState(compliance.mca_compliant),
    };
}

export function emptyRate(): RateForm {
    return {
        season: 'summer',
        rate_type: 'weekly',
        amount_min: '',
        amount_max: '',
        currency: 'EUR',
        contract_terms: '',
        apa_percent: null,
        vat_percent: null,
        valid_from: '',
        valid_to: '',
    };
}

export function emptyOperatingArea(): OperatingAreaForm {
    return { name: '', slug: null, season: '' };
}

export function emptyCrewMember(): CrewMemberForm {
    return {
        role: '',
        name: '',
        nationality: '',
        bio: '',
        photo_url: '',
        tba: false,
    };
}
