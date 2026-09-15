# Cjenovna transparentnost

WooCommerce dodatak za usklađenje s hrvatskim propisima o objavi cjenika i isticanju sidrene cijene.

**Izvor obveze:** NN 101/2026, *Odluka o objavi cjenika proizvoda i usluga* — donesena 10.9.2026., na snazi od 1.10.2026.

---

## Što radi

- **Ističe sidrenu cijenu** uz cijenu proizvoda — koliko je artikl stajao na referentni datum.
- **Objavljuje cjenik** na javnoj adresi, svaki dan, u XML-u i CSV-u. Arhiva 30 dana, stabilna poveznica, REST.
- **Prima podatke iz ERP-a**: odabir datoteke → povezivanje stupaca → probni prolaz → upis.
- **Opcionalno prikazuje najnižu cijenu u 30 dana**, ali samo kad je vlastita evidencija dovoljno duboka da se to smije tvrditi.

## Što ne radi

> **Ne mijenja cijene ni bilo koji drugi podatak o proizvodu.** Ni nazive, opise, kategorije ni zalihe.
>
> Piše isključivo u vlastite tablice. Obrišete li ga, trgovina je točno onakva kakva je bila prije.

Ne donosi ni poslovne odluke: gdje nađe problem, prijavi ga i objasni — ali ne odlučuje što je vaša redovna cijena, treba li ukinuti akciju, ni kako se zove vaša marka.

## Načela

**Prazno nije isto što i izostavljeno.** Polje bez vrijednosti znači „ne znamo"; izostavljen element znači „za ovaj artikl se ne primjenjuje". To su različite tvrdnje i datoteka ih razlikuje.

**Ne tvrdi ono što ne znaš.** Najniža cijena u 30 dana ne prikazuje se dok evidencija ne seže dovoljno daleko. Barkod koji strukturno ne može biti barkod ne objavljuje se. Vrijednost koju je trgovac izjavio bilježi se kao izjava, ne kao mjerenje.

**Pred neočekivanim se staje.** Padne li samoprovjera, jučerašnja datoteka ostaje na snazi i javi se greška. Stari cjenik je bolji od pokvarenog.

**WooCommerce je izvor istine.** Gdje on ima svoje polje — GTIN u kartici Inventar, marka u kutiji Marke — vrijedi njegova vrijednost i čita se uživo. Naša se ne kopira i ne prepisuje preko njegove.

## Sučelje

Tri ekrana i čarobnjak koji se pojavi jednom pa nestane.

| | |
|---|---|
| **Stanje** | odgovara na jedno pitanje: radi li sve. Kad radi, kratak je i dosadan. |
| **Artikli** | uvoz na vrhu, ručna dopuna ispod. |
| **Postavke** | referentni datum, djelatnost, podaci o trgovcu, vrijeme objave. |
| **Napredno** | sve što ne treba svaki dan. |

Problemi se prikazuju kao **nalazi** — što je nađeno, što to znači i što učiniti — poredani po važnosti. Nalaz koji dodatak ne smije riješiti sam nudi popis i objašnjenje, bez gumba. Riješen nalaz nestaje s popisa; problem koji ne postoji se ne spominje.

## Zahtjevi

WordPress 5.9+ · WooCommerce 7.0+ · PHP 7.4+

Radi na dijeljenom hostingu bez SSH-a i bez WP-CLI-ja. Duge operacije idu u pozadini, u malim komadima, s pauzom i povratom. Za dnevnu objavu treba jedan cron redak u cPanelu — dodatak ga ispiše gotovog, preračunatog za vremensku zonu poslužitelja.

## Instalacija

1. Dodaci → Dodaj novi → Pošalji dodatak → odaberite ZIP.
2. Aktivirajte.
3. U izborniku se pojavi **Cjenik i cijene** → **Prvo postavljanje**, četiri koraka.

Detaljno u [PRVO-POSTAVLJANJE.md](PRVO-POSTAVLJANJE.md).

## Licenca

GPL-2.0-or-later
