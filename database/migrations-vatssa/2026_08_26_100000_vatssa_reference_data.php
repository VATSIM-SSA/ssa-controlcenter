<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA reference data: the five areas and the division's ATC positions.
 *
 * This is a MIGRATION, not a seeder, on purpose. Areas and positions are static
 * division reference data that every environment needs, production included, so
 * they must be rebuildable from the repository alone. Seeders here are for dev
 * fixtures (fake members, trainings, feedback) and refuse to run in production.
 *
 * Safety properties, all deliberate:
 *
 *  - Idempotent. Keyed on `callsign`. Re-running changes nothing.
 *  - Never destructive. Positions absent from this list are left alone.
 *    Removing a position is a separate, explicit migration.
 *  - Never overwrites `area_id` on an existing row. Production already holds
 *    the real area assignments; FIR_AREA below is consulted only for rows this
 *    migration inserts, so a wrong guess there cannot damage production data.
 *
 * Provenance: the 404 rows in the retired overlay migration
 * `2025_09_17_100000_update_positions_table.php`, with three fixes applied:
 *
 *  1. FACT_APP, FACT_F_APP and FAOR_APP were each listed twice (re-added in
 *     ssa-controlcenter-custom#8 when they already existed). Deduplicated to
 *     401 rows.
 *  2. 'Port Elizabeth Clearance Delivery' is 33 characters against a
 *     `string('name', 30)` column. Shortened to 'Port Elizabeth Delivery'.
 *  3. The overlay called Schema::create('positions'), which collides with
 *     upstream's own migration. This only writes rows; the schema stays
 *     upstream's.
 */
return new class extends Migration
{
    /**
     * Upstream's countries migration seeds five rows as ids 1-5. Renaming them
     * in place keeps the ids stable, which matters because TrainingFactory
     * hardcodes area_id 1-5.
     */
    private const AREAS = [
        1 => ['name' => 'Southern Africa', 'contact' => 'atc.training@vatssa.com'],
        2 => ['name' => 'Eastern Africa', 'contact' => 'atc.training@vatssa.com'],
        3 => ['name' => 'Western Africa', 'contact' => 'atc.training@vatssa.com'],
        4 => ['name' => 'Central Africa', 'contact' => 'atc.training@vatssa.com'],
        5 => ['name' => 'Vanilla', 'contact' => 'atc.training@vatssa.com'],
    ];

    /**
     * FIR to area, used ONLY when inserting a position that does not exist yet.
     *
     * Derived from the FIR codes, NOT read from production. Check it against the
     * live data before the first fresh-environment build:
     *
     *   SELECT fir, area_id, COUNT(*) FROM positions GROUP BY fir, area_id;
     *
     * An unmapped FIR throws rather than inserting a null area, so adding a FIR
     * to POSITIONS without adding it here fails loudly at migrate time.
     */
    private const FIR_AREA = [
        // Southern Africa
        'AFRS' => 1, 'FAJO' => 1, 'FASA' => 1, 'FBGR' => 1, 'FIMM' => 1, 'FLFI' => 1, 'FMMM' => 1, 'FNAN' => 1, 'FQBE' => 1, 'FVHF' => 1, 'FWLL' => 1, 'FYWF' => 1,
        // Eastern Africa
        'FSSS' => 2, 'HBBA' => 2, 'HKNA' => 2, 'HRYR' => 2, 'HTDC' => 2, 'HUEC' => 2,
        // Western Africa
        'AFRW' => 3, 'DGAC' => 3, 'DNKK' => 3, 'GLRB' => 3, 'GOOO' => 3, 'GVSC' => 3,
        // Central Africa
        'AFRC' => 4, 'FCCC' => 4, 'FZZA' => 4,
    ];

    /** callsign, name, fir, minimum VATSIM rating */
    private const POSITIONS = [
        // AFRC
        ['AFRC_FSS', 'Africa Central Control', 'AFRC', 5],

        // AFRS
        ['AFRS_FSS', 'Africa South Control', 'AFRS', 5],

        // AFRW
        ['AFRW_A_FSS', 'Africa West Control', 'AFRW', 5],
        ['AFRW_B_FSS', 'Africa West Control', 'AFRW', 5],
        ['AFRW_FSS', 'Africa West Control', 'AFRW', 5],

        // DGAC
        ['DBBB_APP', 'Cotonou Approach', 'DGAC', 4],
        ['DBBB_TWR', 'Cotonou Tower', 'DGAC', 3],
        ['DGAA_APP', 'Accra Approach', 'DGAC', 4],
        ['DGAA_GND', 'Accra Ground', 'DGAC', 2],
        ['DGAA_TWR', 'Accra Tower', 'DGAC', 3],
        ['DGAC_CTR', 'Accra Control', 'DGAC', 5],
        ['DGAC_N_CTR', 'Accra Control', 'DGAC', 5],
        ['DGAO_CTR', 'Accra Radio', 'DGAC', 5],
        ['DGLE_APP', 'Tamale Approach', 'DGAC', 4],
        ['DGSI_TWR', 'Kumasi Approach/Tower', 'DGAC', 3],
        ['DGSN_TWR', 'Sunyani Approach/Tower', 'DGAC', 3],
        ['DGTK_TWR', 'Takoradi Approach/Tower', 'DGAC', 3],
        ['DXNG_TWR', 'Niamtougou Tower', 'DGAC', 3],
        ['DXXX_CTR', 'Lome Control', 'DGAC', 5],
        ['DXXX_TWR', 'Lome Tower', 'DGAC', 3],

        // DNKK
        ['DNAA_APP', 'Abuja Approach', 'DNKK', 4],
        ['DNAA_GND', 'Abuja Ground', 'DNKK', 2],
        ['DNAA_TWR', 'Abuja Tower', 'DNKK', 3],
        ['DNAI_TWR', 'Uyo Tower', 'DNKK', 3],
        ['DNAK_TWR', 'Akure Tower', 'DNKK', 3],
        ['DNBE_TWR', 'Benin Tower', 'DNKK', 3],
        ['DNBK_TWR', 'Kebbi Tower', 'DNKK', 3],
        ['DNCA_APP', 'Calabar Tower/Approch', 'DNKK', 4],
        ['DNDS_TWR', 'Dutse Tower', 'DNKK', 3],
        ['DNEN_TWR', 'Enugu Tower/Approach', 'DNKK', 3],
        ['DNES_TWR', 'Escravos Tower', 'DNKK', 3],
        ['DNFB_TWR', 'Finimi Tower', 'DNKK', 3],
        ['DNGO_TWR', 'Gombe Tower', 'DNKK', 3],
        ['DNIB_TWR', 'Ibadan Tower', 'DNKK', 3],
        ['DNIL_APP', 'Ilorin Approach/Tower', 'DNKK', 4],
        ['DNIM_TWR', 'Owerri Tower', 'DNKK', 3],
        ['DNJO_TWR', 'Jos Approach/Tower', 'DNKK', 3],
        ['DNKA_APP', 'Kaduna Tower', 'DNKK', 4],
        ['DNKK_CTR', 'Kano Control', 'DNKK', 5],
        ['DNKK_W_CTR', 'Kano Control', 'DNKK', 5],
        ['DNKN_APP', 'Kano Approach', 'DNKK', 4],
        ['DNKN_TWR', 'Kano Tower', 'DNKK', 3],
        ['DNKT_TWR', 'Katsina Tower', 'DNKK', 3],
        ['DNMA_APP', 'Maiduguri Tower', 'DNKK', 4],
        ['DNMM_APP', 'Lagos Approach', 'DNKK', 4],
        ['DNMM_CTR', 'Lagos West', 'DNKK', 5],
        ['DNMM_DEL', 'Lagos Delivery', 'DNKK', 2],
        ['DNMM_E_GND', 'Lagos Ground', 'DNKK', 2],
        ['DNMM_GND', 'Lagos Ground', 'DNKK', 2],
        ['DNMM_P_DEL', 'Lagos Planner', 'DNKK', 2],
        ['DNMM_TWR', 'Lagos Tower', 'DNKK', 3],
        ['DNMN_TWR', 'Minna Tower', 'DNKK', 3],
        ['DNPO_APP', 'Port Harcourt Approach', 'DNKK', 4],
        ['DNPO_TWR', 'Port Harcourt Tower', 'DNKK', 3],
        ['DNSO_TWR', 'Sokoto Tower', 'DNKK', 3],
        ['DNSU_TWR', 'Osubi Tower', 'DNKK', 3],
        ['DNYO_TWR', 'Yola Tower', 'DNKK', 3],
        ['DNZA_TWR', 'Zaria Tower', 'DNKK', 3],

        // FAJO
        ['FAJO_1_FSS', 'Johannesburg Oceanic', 'FAJO', 5],
        ['FAJO_2_FSS', 'Johannesburg Oceanic', 'FAJO', 5],
        ['FAJO_3_FSS', 'Johannesburg Oceanic', 'FAJO', 5],
        ['FAJO_FSS', 'Johannesburg Oceanic', 'FAJO', 5],

        // FASA
        ['FABE_TWR', 'Bhisho Tower', 'FASA', 3],
        ['FABL_APP', 'Blomfontein Approach', 'FASA', 4],
        ['FABL_TWR', 'Blomfontein Tower', 'FASA', 3],
        ['FABW_I_TWR', 'Karoo Radio', 'FASA', 3],
        ['FACA_CTR', 'Cape Town Area', 'FASA', 5],
        ['FACA_E_CTR', 'Cape Town Area', 'FASA', 5],
        ['FACT_APP', 'Cape Town Approach', 'FASA', 4],
        ['FACT_DEL', 'Cape Town Delivery', 'FASA', 2],
        ['FACT_E_GND', 'Cape Town Ground', 'FASA', 2],
        ['FACT_F_APP', 'Cape Town Director', 'FASA', 4],
        ['FACT_GND', 'Cape Town Ground', 'FASA', 2],
        ['FACT_P_DEL', 'Cape Town Planner', 'FASA', 2],
        ['FACT_TWR', 'Cape Town Tower', 'FASA', 3],
        ['FAEL_APP', 'East London Approach', 'FASA', 4],
        ['FAEL_TWR', 'East London Tower', 'FASA', 3],
        ['FAGC_TWR', 'Grand Central Tower', 'FASA', 3],
        ['FAGG_APP', 'George Approach', 'FASA', 4],
        ['FAGG_TWR', 'George Tower', 'FASA', 3],
        ['FAGM_TWR', 'Rand Tower', 'FASA', 3],
        ['FAHS_APP', 'Hoedspruit Approach', 'FASA', 4],
        ['FAHS_GND', 'Hoedspruit Ground', 'FASA', 2],
        ['FAHS_TWR', 'Hoedspruit Tower', 'FASA', 3],
        ['FAJA_CTR', 'Johannesburg Area', 'FASA', 5],
        ['FAJA_C_CTR', 'Johannesburg Area', 'FASA', 5],
        ['FAJA_E_CTR', 'Johannesburg Area', 'FASA', 5],
        ['FAJA_NW_CTR', 'Johannesburg Area', 'FASA', 5],
        ['FAJA_SE_CTR', 'Johannesburg Area', 'FASA', 5],
        ['FAJA_SW_CTR', 'Johannesburg Area', 'FASA', 5],
        ['FAJA_W_CTR', 'Johannesburg Area', 'FASA', 5],
        ['FAKM_APP', 'Kimberley Approach', 'FASA', 4],
        ['FAKM_A_GND', 'Kimberley Apron Office', 'FASA', 2],
        ['FAKM_TWR', 'Kimberley Tower', 'FASA', 3],
        ['FAKN_APP', 'Kruger Approach', 'FASA', 4],
        ['FAKN_I_APP', 'Kruger Radio', 'FASA', 4],
        ['FALA_GND', 'Lanseria Ground', 'FASA', 2],
        ['FALA_TWR', 'Lanseria Tower', 'FASA', 3],
        ['FALE_APP', 'Durban Approach', 'FASA', 4],
        ['FALE_GND', 'King Shaka Ground', 'FASA', 2],
        ['FALE_I_APP', 'Durban Radio', 'FASA', 4],
        ['FALE_TWR', 'King Shaka Tower', 'FASA', 3],
        ['FALM_APP', 'Makhado Approach', 'FASA', 4],
        ['FALM_GND', 'Makhado Ground', 'FASA', 2],
        ['FALM_TWR', 'Makhado Tower', 'FASA', 3],
        ['FALW_APP', 'Langebaanweg Approach', 'FASA', 4],
        ['FALW_GND', 'Langebaanweg Ground', 'FASA', 2],
        ['FALW_TWR', 'Langebaanweg Tower', 'FASA', 3],
        ['FAMM_TWR', 'Mafikeng Tower', 'FASA', 3],
        ['FAMR_I_CTR', 'Lowveld Information', 'FASA', 5],
        ['FAOB_APP', 'Overberg Approach', 'FASA', 4],
        ['FAOH_I_TWR', 'Oudtshoorn Radio', 'FASA', 3],
        ['FAOR_APP', 'Johannesburg Radar', 'FASA', 4],
        ['FAOR_A_GND', 'O.R. Tambo Apron Office', 'FASA', 2],
        ['FAOR_DEL', 'O.R. Tambo Clearance Delivery', 'FASA', 2],
        ['FAOR_E_TWR', 'O.R. Tambo Tower', 'FASA', 3],
        ['FAOR_F_APP', 'O.R. Tambo Director', 'FASA', 4],
        ['FAOR_GND', 'O.R. Tambo Ground', 'FASA', 2],
        ['FAOR_TWR', 'O.R. Tambo Tower', 'FASA', 3],
        ['FAOR_V_APP', 'Johannesburg Radio', 'FASA', 4],
        ['FAOR_W_APP', 'Johannesburg Radar', 'FASA', 4],
        ['FAPA_I_TWR', 'Port Alfred Radio', 'FASA', 3],
        ['FAPE_APP', 'Port Elizabeth Approach', 'FASA', 4],
        ['FAPE_DEL', 'Port Elizabeth Delivery', 'FASA', 2],
        ['FAPE_TWR', 'Port Elizabeth Tower', 'FASA', 3],
        ['FAPM_TWR', 'Pietermaritzburg Tower', 'FASA', 3],
        ['FAPN_TWR', 'Pilanesburg Tower', 'FASA', 3],
        ['FAPP_TWR', 'Polokwane Tower', 'FASA', 3],
        ['FARB_TWR', 'Richards Bay Tower', 'FASA', 3],
        ['FASA_CTR', 'South Africa Control', 'FASA', 5],
        ['FASK_TWR', 'Swartkops Tower', 'FASA', 3],
        ['FASZ_I_TWR', 'Skukuza Radio', 'FASA', 3],
        ['FAUP_TWR', 'Upington Tower', 'FASA', 3],
        ['FAUT_TWR', 'Mthatha Tower', 'FASA', 3],
        ['FAVG_TWR', 'Virginia Tower', 'FASA', 3],
        ['FAWB_GND', 'Wonderboom Ground', 'FASA', 2],
        ['FAWB_TWR', 'Wonderboom Tower', 'FASA', 3],
        ['FAWK_GND', 'Waterkloof Ground', 'FASA', 2],
        ['FAWK_TWR', 'Waterkloof Tower', 'FASA', 3],
        ['FAYP_GND', 'Ysterplaat Ground', 'FASA', 2],
        ['FAYP_TWR', 'Ysterplaat Tower', 'FASA', 3],
        ['FDMS_GND', 'Matsapha Ground', 'FASA', 2],
        ['FDMS_TWR', 'Matsaph Tower', 'FASA', 3],
        ['FDSK_APP', 'Matsapha Approach', 'FASA', 4],
        ['FDSK_GND', 'Sikhuphe Ground', 'FASA', 2],
        ['FXMM_APP', 'Maseru Approach', 'FASA', 4],
        ['FXMM_GND', 'Maseru Ground', 'FASA', 2],
        ['FXMM_TWR', 'Maseru Tower', 'FASA', 3],

        // FBGR
        ['FBGR_CTR', 'Gaborone Control', 'FBGR', 5],
        ['FBKE_APP', 'Kasane Approach', 'FBGR', 4],
        ['FBKE_TWR', 'Kasane Tower', 'FBGR', 3],
        ['FBLV_TWR', 'Limpopo Tower', 'FBGR', 3],
        ['FBMN_APP', 'Maun Approach', 'FBGR', 4],
        ['FBMN_GND', 'Maun Ground', 'FBGR', 2],
        ['FBMN_TWR', 'Maun Tower', 'FBGR', 3],
        ['FBPM_APP', 'PG Matante Approach', 'FBGR', 4],
        ['FBPM_TWR', 'PG Matante Tower', 'FBGR', 3],
        ['FBSK_APP', 'Gaborone Approach', 'FBGR', 4],
        ['FBSK_GND', 'Gaborone Ground', 'FBGR', 2],
        ['FBSK_TWR', 'Gaborone Tower', 'FBGR', 3],
        ['FBSP_TWR', 'Selebi Tower', 'FBGR', 3],

        // FCCC
        ['FCBB_APP', 'Brazzaville Approach', 'FCCC', 4],
        ['FCBB_TWR', 'Brazzaville Tower', 'FCCC', 3],
        ['FCCC_CTR', 'Brazzaville Control', 'FCCC', 5],
        ['FCPD_TWR', 'Dolisie Tower', 'FCCC', 3],
        ['FCPP_APP', 'Pointe Noire Approach', 'FCCC', 4],
        ['FCPP_TWR', 'Pointe Noir Tower', 'FCCC', 3],
        ['FEFF_TWR', 'Bangui Tower', 'FCCC', 3],
        ['FGSL_TWR', 'Malabo Tower', 'FCCC', 3],
        ['FKKD_TWR', 'Douala Tower', 'FCCC', 3],
        ['FKKK_CTR', 'Doual Control', 'FCCC', 5],
        ['FKKN_TWR', 'Ngaoundere Tower', 'FCCC', 3],
        ['FKYS_APP', 'Nsimalen Approach', 'FCCC', 4],
        ['FKYS_TWR', 'Nsimalen Tower', 'FCCC', 3],
        ['FOOG_APP', 'Port Gentil Approach', 'FCCC', 4],
        ['FOOG_TWR', 'Port Gentil Tower', 'FCCC', 3],
        ['FOOL_TWR', 'Libreville Tower', 'FCCC', 3],
        ['FOON_TWR', 'Franceville Tower', 'FCCC', 3],
        ['FOOO_CTR', 'Libreville Control', 'FCCC', 5],
        ['FPST_APP', 'Sao Tome Approach', 'FCCC', 4],
        ['FPST_TWR', 'Sao Tome Tower', 'FCCC', 3],

        // FIMM
        ['FIMM_CTR', 'Mauritius Centre', 'FIMM', 5],
        ['FIMM_FSS', 'Mauritius Radio', 'FIMM', 5],
        ['FIMP_APP', 'Mauritius Approach', 'FIMM', 4],
        ['FIMP_TWR', 'Mauritius Tower', 'FIMM', 3],
        ['FIMR_TWR', 'Corail Tower', 'FIMM', 3],
        ['FJDG_GND', 'Diego Garcia Ground', 'FIMM', 2],
        ['FJDG_TWR', 'Diego Garcia Tower', 'FIMM', 3],

        // FLFI
        ['FLCP_I_TWR', 'Chipata Tower', 'FLFI', 3],
        ['FLFI_CTR', 'Lusaka Control', 'FLFI', 5],
        ['FLHN_APP', 'Livingstone Approach', 'FLFI', 4],
        ['FLHN_TWR', 'Livingstone Tower', 'FLFI', 3],
        ['FLKK_APP', 'Lusaka Approach', 'FLFI', 4],
        ['FLKK_TWR', 'Lusaka Tower', 'FLFI', 3],
        ['FLKS_I_TWR', 'Kasama Tower', 'FLFI', 3],
        ['FLMA_I_TWR', 'Mansa Tower', 'FLFI', 3],
        ['FLMF_APP', 'Mfuwe Approach', 'FLFI', 4],
        ['FLMG_I_TWR', 'Mongu Tower', 'FLFI', 3],
        ['FLND_APP', 'Ndola Approach', 'FLFI', 4],
        ['FLND_TWR', 'Ndola Tower', 'FLFI', 3],
        ['FLSW_I_TWR', 'Solwezi Tower', 'FLFI', 3],

        // FMMM
        ['FMCH_TWR', 'Moroni Tower', 'FMMM', 3],
        ['FMCZ_TWR', 'Dzaoudzi Tower', 'FMMM', 3],
        ['FMEE_APP', 'Roland Garros Approach', 'FMMM', 4],
        ['FMEE_GND', 'Roland Garros Ground', 'FMMM', 2],
        ['FMEE_TWR', 'Roland Garros Tower', 'FMMM', 3],
        ['FMMI_0_GND', 'Antananarivo Ground', 'FMMM', 2],
        ['FMMI_APP', 'Antananarivo Approach', 'FMMM', 4],
        ['FMMI_TWR', 'Antananarivo Tower', 'FMMM', 3],
        ['FMMM_CTR', 'Antananarivo Control', 'FMMM', 5],
        ['FMMT_TWR', 'Toamasino Tower', 'FMMM', 3],
        ['FMNM_TWR', 'Mahajanga Tower', 'FMMM', 3],
        ['FMNN_TWR', 'Nosy Be Tower', 'FMMM', 3],

        // FNAN
        ['FHSH_APP', 'St Helena Approach', 'FNAN', 4],
        ['FHSH_TWR', 'St Helena Tower', 'FNAN', 3],
        ['FNAN_CTR', 'Luanda Control', 'FNAN', 5],
        ['FNBG_I_TWR', 'Benguela Information', 'FNAN', 3],
        ['FNCA_TWR', 'Cabina Tower', 'FNAN', 3],
        ['FNGI_I_TWR', 'Ondjiva Information', 'FNAN', 3],
        ['FNHU_I_TWR', 'Huambo Information', 'FNAN', 3],
        ['FNLU_APP', 'Luanda Approach', 'FNAN', 4],
        ['FNLU_GND', 'Luanda Ground', 'FNAN', 2],
        ['FNLU_TWR', 'Luanda Tower', 'FNAN', 3],
        ['FNMO_I_TWR', 'Namibe Information', 'FNAN', 3],
        ['FNSA_I_TWR', 'Saurimo Tower', 'FNAN', 3],
        ['FNUB_I_TWR', 'Mukanka Tower', 'FNAN', 3],
        ['FNUE_I_TWR', 'Luena Tower', 'FNAN', 3],

        // FQBE
        ['FQBE_CTR', 'Beira Control', 'FQBE', 5],
        ['FQBE_S_CTR', 'Maputo Control', 'FQBE', 5],
        ['FQBR_APP', 'Beira Approach', 'FQBE', 4],
        ['FQBR_GND', 'Beira Ground', 'FQBE', 2],
        ['FQBR_TWR', 'Beira Tower', 'FQBE', 3],
        ['FQLC_TWR', 'Lichinga Tower', 'FQBE', 3],
        ['FQMA_APP', 'Maputo Approach', 'FQBE', 4],
        ['FQMA_GND', 'Maputo Ground', 'FQBE', 2],
        ['FQMA_TWR', 'Maputo Tower', 'FQBE', 3],
        ['FQNC_APP', 'Nacala Approach', 'FQBE', 4],
        ['FQNC_TWR', 'Nacala Tower', 'FQBE', 3],
        ['FQNP_TWR', 'Nampula Tower', 'FQBE', 3],
        ['FQPB_APP', 'Pemba Approach', 'FQBE', 4],
        ['FQQL_APP', 'Quelimane Approach', 'FQBE', 4],
        ['FQTT_APP', 'Tete Approach', 'FQBE', 4],
        ['FQVL_APP', 'Vilankulo Approach', 'FQBE', 4],

        // FSSS
        ['FSIA_APP', 'Seychelles Approach', 'FSSS', 4],
        ['FSIA_GND', 'Seychelles Ground', 'FSSS', 2],
        ['FSIA_TWR', 'Seychelles Tower', 'FSSS', 3],
        ['FSPP_TWR', 'Praslin Tower', 'FSSS', 3],
        ['FSSS_CTR', 'Seychelles Control', 'FSSS', 5],
        ['FSSS_FSS', 'Seychelles Control', 'FSSS', 5],

        // FVHF
        ['FVCP_TWR', 'Prince Tower', 'FVHF', 3],
        ['FVCZ_APP', 'Buffalo Approach', 'FVHF', 4],
        ['FVFA_APP', 'Falls Approach', 'FVHF', 4],
        ['FVHF_CTR', 'Harare East Control', 'FVHF', 5],
        ['FVHF_W_CTR', 'Harare West Control', 'FVHF', 5],
        ['FVJN_APP', 'Nkomo Approach', 'FVHF', 4],
        ['FVJN_TWR', 'Nkomo Tower', 'FVHF', 3],
        ['FVKB_APP', 'Kariba Approach', 'FVHF', 4],
        ['FVMV_APP', 'Masvingo Approach', 'FVHF', 4],
        ['FVRG_APP', 'Harare Approach', 'FVHF', 4],
        ['FVRG_GND', 'Mugabe Ground', 'FVHF', 2],
        ['FVRG_TWR', 'Mugabe Tower', 'FVHF', 3],
        ['FVTL_APP', 'Thornhill Approach', 'FVHF', 4],
        ['FVWN_APP', 'Park Approach', 'FVHF', 4],

        // FWLL
        ['FWCL_APP', 'Chileka Approach', 'FWLL', 4],
        ['FWCL_TWR', 'Chileka Tower', 'FWLL', 3],
        ['FWKA_I_TWR', 'Karonga Tower', 'FWLL', 3],
        ['FWKI_APP', 'Lilongwe Approach', 'FWLL', 4],
        ['FWKI_TWR', 'Lumbadzi Tower', 'FWLL', 3],
        ['FWLL_CTR', 'Lilongwe Control', 'FWLL', 5],

        // FYWF
        ['FYGF_TWR', 'Grootfontein Tower', 'FYWF', 3],
        ['FYKM_TWR', 'Katima Tower', 'FYWF', 3],
        ['FYKT_TWR', 'Keetmanshoop Tower', 'FYWF', 3],
        ['FYLZ_TWR', 'Luderitz Tower', 'FYWF', 3],
        ['FYOA_APP', 'Ondangwa Approach/Tower', 'FYWF', 4],
        ['FYSM_TWR', 'Swakopmund Tower', 'FYWF', 3],
        ['FYWB_APP', 'Walvis Bay Approach/Tower', 'FYWF', 4],
        ['FYWB_GND', 'Walvis Bay Ground', 'FYWF', 2],
        ['FYWE_A_GND', 'Eros Apron', 'FYWF', 2],
        ['FYWE_TWR', 'Eros Tower', 'FYWF', 3],
        ['FYWH_APP', 'Windhoek Approach', 'FYWF', 4],
        ['FYWH_A_GND', 'Windhoek Apron', 'FYWF', 2],
        ['FYWH_CTR', 'Windhoek Radar', 'FYWF', 5],
        ['FYWH_TWR', 'Windhoek Tower', 'FYWF', 3],

        // FZZA
        ['FZAA_APP', 'Kinshasa Approach', 'FZZA', 4],
        ['FZAA_TWR', 'Kinshasa Tower', 'FZZA', 3],
        ['FZAM_TWR', 'Matadi Tower', 'FZZA', 3],
        ['FZEA_TWR', 'Mbandaka Tower', 'FZZA', 3],
        ['FZFD_TWR', 'Gbadolite Tower', 'FZZA', 3],
        ['FZFK_TWR', 'Gemena Tower', 'FZZA', 3],
        ['FZIC_TWR', 'Kisangani Tower', 'FZZA', 3],
        ['FZJH_TWR', 'Isiro Matari Tower', 'FZZA', 3],
        ['FZKA_TWR', 'Bunia Tower', 'FZZA', 3],
        ['FZKJ_TWR', 'Buta Zega Tower', 'FZZA', 3],
        ['FZMA_TWR', 'Bukavu Tower', 'FZZA', 3],
        ['FZNA_TWR', 'Goma Tower', 'FZZA', 3],
        ['FZOA_TWR', 'Kindu Tower', 'FZZA', 3],
        ['FZQA_APP', 'Lubumbashi Approach', 'FZZA', 4],
        ['FZQA_TWR', 'Lubumbashi Tower', 'FZZA', 3],
        ['FZQM_TWR', 'Kolwezi Tower', 'FZZA', 3],
        ['FZRF_TWR', 'Kalemie Tower', 'FZZA', 3],
        ['FZUA_TWR', 'Kananga Tower', 'FZZA', 3],
        ['FZWA_APP', 'Mbuji-Mayi Approach', 'FZZA', 4],
        ['FZZA_CTR', 'Kinshasa Control', 'FZZA', 5],
        ['FZZA_E_CTR', 'Kisangani Control', 'FZZA', 5],
        ['FZZA_S_CTR', 'Lubumbashi Control', 'FZZA', 5],

        // GLRB
        ['GFLL_APP', 'Freetown Approach', 'GLRB', 4],
        ['GFLL_TWR', 'Freetown Tower', 'GLRB', 3],
        ['GLRB_APP', 'Roberts Approach', 'GLRB', 4],
        ['GLRB_CTR', 'Roberts Control', 'GLRB', 5],
        ['GLRB_GND', 'Roberts Ground', 'GLRB', 2],
        ['GLRB_TWR', 'Roberts Tower', 'GLRB', 3],
        ['GUCY_APP', 'Conakry Approach', 'GLRB', 4],
        ['GUCY_TWR', 'Conakry Tower', 'GLRB', 3],
        ['GUOK_TWR', 'Boke Tower', 'GLRB', 3],

        // GOOO
        ['DIAP_APP', 'Abidjan Approach', 'GOOO', 4],
        ['DIAP_TWR', 'Abidjan Tower', 'GOOO', 3],
        ['DIBK_TWR', 'Bouake Tower', 'GOOO', 3],
        ['DIII_CTR', 'Abidjan Control', 'GOOO', 5],
        ['DISP_TWR', 'San Pedro Tower', 'GOOO', 3],
        ['DIYO_TWR', 'Yamoussoukro Tower', 'GOOO', 3],
        ['GAAA_CTR', 'Bamako Control', 'GOOO', 5],
        ['GABS_TWR', 'Bamako Tower', 'GOOO', 3],
        ['GAKD_TWR', 'Kayes Dag Dag Tower', 'GOOO', 3],
        ['GAMB_TWR', 'Mopti Tower', 'GOOO', 3],
        ['GBYD_APP', 'Banjul Approach', 'GOOO', 4],
        ['GBYD_TWR', 'Banjul Tower', 'GOOO', 3],
        ['GGOV_APP', 'Bissau Approach', 'GOOO', 4],
        ['GGOV_TWR', 'Bissau Tower', 'GOOO', 3],
        ['GOBD_APP', 'Diass Approach', 'GOOO', 4],
        ['GOBD_DEL', 'Dakar Delivery', 'GOOO', 2],
        ['GOBD_GND', 'Diass Ground', 'GOOO', 2],
        ['GOBD_TWR', 'Diass Tower', 'GOOO', 3],
        ['GOBD_U_APP', 'Dakar Approach', 'GOOO', 4],
        ['GOGG_TWR', 'Ziguinchor Tower', 'GOOO', 3],
        ['GOGS_TWR', 'Cap-Skirring Tower', 'GOOO', 3],
        ['GOOC_A_FSS', 'Dakar Radio', 'GOOO', 5],
        ['GOOC_B_FSS', 'Dakar Radio', 'GOOO', 5],
        ['GOOC_FSS', 'Dakar Radio', 'GOOO', 5],
        ['GOOO_CTR', 'Dakar Control', 'GOOO', 5],
        ['GOOY_GND', 'Yoff Ground', 'GOOO', 2],
        ['GOOY_TWR', 'Yoff Tower', 'GOOO', 3],
        ['GOSS_TWR', 'Saint Louis Tower', 'GOOO', 3],
        ['GQNO_TWR', 'Nouakchott Tower', 'GOOO', 3],
        ['GQPP_TWR', 'Nouadhibou Tower', 'GOOO', 3],
        ['GQQQ_CTR', 'Nouakchott Control', 'GOOO', 5],

        // GVSC
        ['GVAC_0_GND', 'Amilcabral Ground', 'GVSC', 2],
        ['GVAC_TWR', 'Amilcabral Tower', 'GVSC', 3],
        ['GVBA_TWR', 'Boavista Tower', 'GVSC', 3],
        ['GVMA_I_TWR', 'Maio Information', 'GVSC', 3],
        ['GVNP_TWR', 'Praia Tower', 'GVSC', 3],
        ['GVSC_APP', 'Sal Approach', 'GVSC', 4],
        ['GVSC_CTR', 'Sal Control', 'GVSC', 5],
        ['GVSC_DEL', 'Sal Delivery', 'GVSC', 2],
        ['GVSC_U_APP', 'Sal Approach', 'GVSC', 4],
        ['GVSF_I_TWR', 'Sanfilipe Information', 'GVSC', 3],
        ['GVSN_I_TWR', 'Sanicolau Informatio', 'GVSC', 3],
        ['GVSV_TWR', 'San Vicente Tower', 'GVSC', 3],

        // HBBA
        ['HBBA_APP', 'Bujumbura Approach', 'HBBA', 4],
        ['HBBA_TWR', 'Bujumbura Tower', 'HBBA', 3],

        // HKNA
        ['HKEL_APP', 'Eldoret Approach', 'HKNA', 4],
        ['HKEL_TWR', 'Eldoret Tower', 'HKNA', 3],
        ['HKEM_TWR', 'Embu Tower', 'HKNA', 3],
        ['HKJK_APP', 'Nairobi Radar', 'HKNA', 4],
        ['HKJK_GND', 'Nairobi Apron', 'HKNA', 2],
        ['HKJK_TWR', 'Nairobi Tower', 'HKNA', 3],
        ['HKKI_GND', 'Kisumu Ground', 'HKNA', 2],
        ['HKKI_TWR', 'Kisumu Tower', 'HKNA', 3],
        ['HKLK_GND', 'Loki Ground', 'HKNA', 2],
        ['HKLK_TWR', 'Loki Tower', 'HKNA', 3],
        ['HKML_GND', 'Malindi Ground', 'HKNA', 2],
        ['HKML_TWR', 'Malindi Tower', 'HKNA', 3],
        ['HKMO_APP', 'Mombasa Radar', 'HKNA', 4],
        ['HKMO_GND', 'Mombasa Ground', 'HKNA', 2],
        ['HKMO_TWR', 'Mombasa Tower', 'HKNA', 3],
        ['HKNA_CTR', 'Nairobi Control', 'HKNA', 5],
        ['HKNL_APP', 'Nanyuki Approach', 'HKNA', 4],
        ['HKNW_GND', 'Wilson Ground', 'HKNA', 2],
        ['HKNW_TWR', 'Wilson Tower', 'HKNA', 3],
        ['HKRE_TWR', 'Eastleigh Tower', 'HKNA', 3],
        ['HKWJ_GND', 'Wajir Ground', 'HKNA', 2],
        ['HKWJ_TWR', 'Wajir Tower', 'HKNA', 3],

        // HRYR
        ['HRYG_TWR', 'Gisenyl Tower', 'HRYR', 3],
        ['HRYR_APP', 'Kigali Approach', 'HRYR', 4],
        ['HRYR_TWR', 'Kigali Tower', 'HRYR', 3],
        ['HRZA_TWR', 'Kamembe Tower', 'HRYR', 3],

        // HTDC
        ['HTDA_APP', 'Dar-Es-Salaam Approach', 'HTDC', 4],
        ['HTDA_GND', 'Dar-Es-Salaam Ground', 'HTDC', 2],
        ['HTDA_TWR', 'Dar-Es-Salaam Tower', 'HTDC', 3],
        ['HTDC_CTR', 'Dar-Es-Salaam Control', 'HTDC', 5],
        ['HTDO_APP', 'Dodoma Approach', 'HTDC', 4],
        ['HTKJ_APP', 'Kilimanjaro Approach', 'HTDC', 4],
        ['HTKJ_TWR', 'Kilimamjaro Tower', 'HTDC', 3],
        ['HTMW_APP', 'Mwanza Approach', 'HTDC', 4],
        ['HTMW_TWR', 'Mwanza Tower', 'HTDC', 3],
        ['HTZA_APP', 'Zanzibar Approach', 'HTDC', 4],

        // HUEC
        ['HUEC_CTR', 'Entebbe Control', 'HUEC', 5],
        ['HUEN_APP', 'Entebbe Approach', 'HUEC', 4],
        ['HUEN_GND', 'Entebbe Ground', 'HUEC', 2],
        ['HUEN_TWR', 'Entebbe Tower', 'HUEC', 3],
        ['HUSO_APP', 'Soroti Approach', 'HUEC', 4],
        ['HUSO_TWR', 'Soroti Tower', 'HUEC', 3],
    ];

    public function up(): void
    {
        foreach (self::AREAS as $id => $area) {
            DB::table('areas')->where('id', $id)->update($area);
        }

        $existing = DB::table('positions')->pluck('id', 'callsign');

        foreach (self::POSITIONS as [$callsign, $name, $fir, $rating]) {
            if (! array_key_exists($fir, self::FIR_AREA)) {
                throw new \RuntimeException("FIR {$fir} has no area in FIR_AREA (position {$callsign}).");
            }

            $attributes = ['name' => $name, 'fir' => $fir, 'rating' => $rating];

            if ($existing->has($callsign)) {
                // area_id deliberately omitted: production owns that value.
                DB::table('positions')->where('callsign', $callsign)->update($attributes);

                continue;
            }

            DB::table('positions')->insert($attributes + [
                'callsign' => $callsign,
                'area_id' => self::FIR_AREA[$fir],
            ]);
        }
    }

    /**
     * Not reversible. Rolling back would delete positions that trainings,
     * bookings and endorsements reference by id.
     */
    public function down(): void
    {
        // no-op
    }
};
