<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

final class AbsenceErpContract
{
    /** @return list<array{field:string,type:string,required:bool,description:string}> */
    public static function apiFields(): array
    {
        return [
            ['field' => 'request_id', 'type' => 'string', 'required' => true, 'description' => 'Identifiant stable et unique de la demande ERP.'],
            ['field' => 'employee_number', 'type' => 'string', 'required' => true, 'description' => 'Matricule exact de l’employé dans AMS.'],
            ['field' => 'status_code', 'type' => 'string', 'required' => true, 'description' => 'Code ERP du statut; doit correspondre au libellé transmis.'],
            ['field' => 'status_label', 'type' => 'string', 'required' => true, 'description' => 'Libellé ERP du statut.'],
            ['field' => 'type_code', 'type' => 'string', 'required' => true, 'description' => 'Code ERP du type de congé.'],
            ['field' => 'type_label', 'type' => 'string', 'required' => true, 'description' => 'Libellé du type de congé.'],
            ['field' => 'day_type', 'type' => 'string', 'required' => true, 'description' => 'Type de jour fourni par l’ERP.'],
            ['field' => 'day_part', 'type' => 'string', 'required' => true, 'description' => 'F pour journée complète, H pour demi-journée.'],
            ['field' => 'duration_hours', 'type' => 'number', 'required' => true, 'description' => 'Durée en heures; positive et cohérente avec la période.'],
            ['field' => 'start_date', 'type' => 'date', 'required' => true, 'description' => 'Date ISO 8601 YYYY-MM-DD, année comprise entre 2000 et 2100.'],
            ['field' => 'end_date', 'type' => 'date', 'required' => true, 'description' => 'Date ISO 8601 YYYY-MM-DD; période maximale de 366 jours.'],
            ['field' => 'time_start', 'type' => 'time|null', 'required' => false, 'description' => 'Heure HH:MM ou HH:MM:SS; obligatoire pour une demi-journée.'],
            ['field' => 'time_end', 'type' => 'time|null', 'required' => false, 'description' => 'Heure HH:MM ou HH:MM:SS; obligatoire et après time_start pour une demi-journée.'],
            ['field' => 'source_updated_at', 'type' => 'datetime', 'required' => true, 'description' => 'Date/heure ISO 8601 du dernier changement; nécessaire pour détecter les mises à jour.'],
        ];
    }

    /** @return list<string> */
    public static function manualHeaders(): array
    {
        return [
            'Leave Request Number',
            'Employee Number',
            'Day Type',
            'Half / Full Day',
            'Leave Duration',
            'Leave Begin Date',
            'Leave End Date',
            'Leave Status',
            'Leave Status',
            'Leave Type',
            'Leave Type',
            'Time In',
            'Time Out',
        ];
    }

    public static function createManualTemplate(): Spreadsheet
    {
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Modele import');
        foreach (self::manualHeaders() as $index => $header) {
            $cell = [$index + 1, 1];
            $sheet->setCellValueExplicit($cell, $header, DataType::TYPE_STRING);
        }

        foreach ([6, 7] as $column) {
            $sheet->getStyle([$column, 2, $column, 10001])
                ->getNumberFormat()
                ->setFormatCode('yyyy-mm-dd');
        }
        foreach (range(1, count(self::manualHeaders())) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $sheet->getStyle('A1:M1')->getAlignment()->setWrapText(true);
        $sheet->setAutoFilter('A1:M1');

        $guide = $workbook->createSheet();
        $guide->setTitle('Guide');
        $guide->fromArray([
            ['Guide du modèle d’import AMS'],
            ['Les noms de colonnes de la première feuille doivent être conservés.'],
            ['Dates : format recommandé YYYY-MM-DD (exemple : 2026-11-06).'],
            ['Le classeur accepte également les dates Excel reconnues par Excel.'],
            ['Half / Full Day : F = journée complète; H = demi-journée sur une seule date.'],
            ['Pour H, Time In et Time Out doivent contenir des heures HH:MM[:SS] valides.'],
            ['Les colonnes Leave Status et Leave Type sont volontairement répétées : code puis libellé.'],
            ['Statuts OBR actuellement reconnus : 50, 55, 58, 59, 60, 72 et 77 avec leur libellé officiel.'],
            ['Un matricule doit correspondre à un seul employé actif du tenant.'],
            ['Une ligne absente d’un nouvel import ne signifie pas une annulation.'],
            ['L’import manuel actuel applique le mapping de statuts OBR et enregistre la source OBR.'],
            ['Ne pas supprimer les demandes déjà importées pour simuler une annulation.'],
        ], null, 'A1');
        $guide->getColumnDimension('A')->setWidth(110);
        $guide->getStyle('A1')->getFont()->setBold(true);
        $guide->getStyle('A1:A12')->getAlignment()->setWrapText(true);
        $guide->getStyle('A1:A12')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $workbook->setActiveSheetIndex(0);

        return $workbook;
    }
}
