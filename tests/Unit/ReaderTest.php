<?php

namespace Tests\Unit;

use App\Portal\Intake\Matching;
use App\Portal\Intake\Rules;
use PHPUnit\Framework\TestCase;

/* The rule-based reader and the patient matching: pure functions, fed with page texts. */
class ReaderTest extends TestCase
{
    public function test_amounts_are_read_in_the_ways_invoices_write_them(): void
    {
        $this->assertSame(1250.0, Rules::parseAmount('1 250,000 DT')['value']);
        $this->assertSame(1234.5, Rules::parseAmount('1.234,50 EUR')['value']);
        $this->assertSame(1234.5, Rules::parseAmount('1,234.50 USD')['value']);
        $this->assertSame(9.0, Rules::parseAmount('9,000', 'TND')['value'], 'three decimals in dinars');
        $this->assertSame(1234.0, Rules::parseAmount('1,234 USD')['value']);
        $this->assertNull(Rules::parseAmount('no number'));
    }

    public function test_a_document_is_classified_by_its_title_in_three_languages(): void
    {
        $this->assertSame('invoice', Rules::classify("INVOICE\nInvoice No: INV-1\nTotal amount due: 10.00 USD\nSomething more to read here")['id']);
        $this->assertSame('prescription', Rules::classify("ORDONNANCE\nDoliprane 1 g : 1 comprimé matin et soir pendant 5 jours")['id']);
        $this->assertSame('invoice', Rules::classify("فاتورة\nرقم الفاتورة: 1234\nالمجموع: 100 دينار تونسي للمريض")['id']);
        $this->assertSame('unreadable', Rules::classify('  scan ')['id']);
    }

    public function test_an_invoice_gives_its_fields_with_where_they_were_read(): void
    {
        $text = "Carthage Medical Centre\nINVOICE\nInvoice No: INV-77\nPatient: LEILA MANSOUR\nDate of birth: 12/04/1988\nCard: VS-4410-2201-8845\nDate of service: 15/09/2026\nDiagnosis: Low back pain (ICD-10 M54.5)\nConsultation   1   80.00\nLumbar X-ray   1   45.50\nTotal amount due: 125.50 TND";
        $f = Rules::extractFields(['text' => $text, 'pages' => [1], 'type' => 'invoice']);

        $this->assertSame('INV-77', $f['invoiceNo']['value']);
        $this->assertSame('Leila Mansour', $f['patientName']['value']);
        $this->assertSame('1988-04-12', $f['dob']['value']);
        $this->assertSame('VS-4410-2201-8845', $f['cardNo']['value']);
        $this->assertSame('2026-09-15', $f['serviceDate']['value']);
        $this->assertSame(125.5, $f['total']['value']);
        $this->assertSame('TND', $f['currency']['value']);
        $this->assertSame('Low back pain', $f['diagnosisText']['value']);
        $this->assertSame('M54.5', $f['icdPrinted'][0]['value']);
        $this->assertCount(2, $f['lines']);
        $this->assertSame(45.5, $f['lines'][1]['amount']);
        $this->assertSame(1, $f['total']['page']);
        $this->assertStringContainsString('125.50', $f['total']['snippet']);
        $this->assertNull(Rules::lineTotalsSignal($f), 'the lines add up');
        $f['total']['value'] = 200;
        $this->assertSame('totals', Rules::lineTotalsSignal($f)['kind']);
    }

    public function test_medicines_and_tests_are_read_from_their_own_documents(): void
    {
        $rx = Rules::extractFields(['text' => "ORDONNANCE\nDr. Sami Trabelsi\n1. Ibuprofen 400 mg tablet — 1 tablet three times a day for 5 days\n- Amoxicilline 1 g : 1 comprimé matin et soir pendant 7 jours", 'pages' => [1], 'type' => 'prescription']);
        $this->assertSame(['Ibuprofen', 'Amoxicilline'], array_column($rx['medications'], 'name'));
        $this->assertSame('400 mg', $rx['medications'][0]['strength']);
        $this->assertSame('for 5 days', $rx['medications'][0]['duration']);

        $lab = Rules::extractFields(['text' => "LABORATORY REPORT\nFasting glucose   1.32   g/L   0.70 - 1.10   H\nFerritin 9 ng/mL 15 - 150 L\nHemoglobin   94   g/dL   12 - 16", 'pages' => [1], 'type' => 'lab_result']);
        $this->assertSame('High', $lab['tests'][0]['flag']);
        $this->assertSame('Low', $lab['tests'][1]['flag']);
        $this->assertStringContainsString('decimal point', $lab['tests'][2]['doubt'], 'a lost decimal point is put to a person');
    }

    public function test_a_file_splits_when_the_patient_or_the_kind_of_document_changes(): void
    {
        $docs = Rules::splitPages(["INVOICE\nPatient: Leila Mansour\nInvoice No: INV-1\nTotal: 10.00 USD", "Page 2 of 2\nPatient: Leila Mansour\nMore lines of the same invoice", "MEDICAL REPORT\nPatient: Sami Gharbi\nDiagnosis: Asthma and more words"]);
        $this->assertSame([[1, 2], [3]], array_column($docs, 'pages'));
    }

    public function test_verification_never_changes_a_value_and_says_what_does_not_hold_up(): void
    {
        $out = Rules::verifyFields('invoice', ['serviceDate' => ['value' => '2030-01-01'], 'dob' => ['value' => '1988-04-12'], 'total' => ['value' => 0]], '', '2026-11-02');
        $texts = implode(' | ', array_column($out, 'text'));
        $this->assertStringContainsString('in the future', $texts);
        $this->assertStringContainsString('Check the amount', $texts);
        $this->assertStringContainsString('Not found on this document: patient name', $texts);
    }

    public function test_an_edited_pdf_is_flagged(): void
    {
        $this->assertNull(Rules::pdfEditSignal("%PDF-1.4\n/CreationDate (D:20260101120000)\n/ModDate (D:20260101120000)\n%%EOF\n"));
        $this->assertSame('edited', Rules::pdfEditSignal("%PDF-1.4\n%%EOF\nmore\n%%EOF\n")['kind']);
        $this->assertSame('edited', Rules::pdfEditSignal("%PDF-1.4\n/Producer (iLovePDF)\n%%EOF\n")['kind']);
    }

    public function test_names_match_across_spellings_and_order(): void
    {
        $this->assertGreaterThan(0.95, Matching::nameSimilarity('Mansour Leila', 'Leila Mansour'));
        $this->assertGreaterThan(0.8, Matching::nameSimilarity('Dr. Leïla Mansur', 'Leila Mansour'));
        $this->assertLessThan(0.6, Matching::nameSimilarity('Sami Gharbi', 'Leila Mansour'));
        $this->assertSame('VS-4410-2201-8845', Matching::normCard('vs 4410.2201 8845'));
        $this->assertSame('1988-04-12', Matching::normDate('12/04/1988'));
        $this->assertSame('2026-10-01', Matching::normDate('1er octobre 2026'));
        $this->assertNull(Matching::normDate('31/02/2026'));
    }

    public function test_a_card_on_someone_elses_name_is_a_persons_decision(): void
    {
        $member = ['id' => 'MBR-1', 'name' => 'Leila Mansour', 'dob' => '1988-04-12', 'cardNo' => 'VS-4410-2201-8845'];
        $sure = Matching::scoreCandidate(['cardNo' => 'VS-4410-2201-8845', 'name' => 'Leila Mansour', 'dob' => '12/04/1988'], $member);
        $this->assertSame(1.0, (float) $sure['score']);
        $this->assertTrue($sure['dobMatches']);
        $other = Matching::scoreCandidate(['cardNo' => 'VS-4410-2201-8845', 'name' => 'Sami Gharbi'], $member);
        $this->assertSame(0.6, $other['score']);
        $this->assertFalse($other['identity']);
        $wrongDob = Matching::scoreCandidate(['name' => 'Leila Mansour', 'dob' => '01/01/1990'], $member);
        $this->assertLessThanOrEqual(0.5, $wrongDob['score']);
    }
}
