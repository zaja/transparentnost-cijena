# Cjenovna transparentnost

WooCommerce dodatak za usklađenje s hrvatskim propisima o objavi cjenika i isticanju sidrene cijene.

**Izvor obveze:** NN 101/2026, *Odluka o objavi cjenika proizvoda i usluga* — donesena 10.9.2026., na snazi od 1.10.2026.

---

## Što radi

- **Ističe sidrenu cijenu** uz cijenu proizvoda — koliko je artikl stajao na referentni datum. Artikl uveden poslije tog datuma nosi cijenu po kojoj je prvi put ponuđen, uz svoj datum.
- **Objavljuje cjenik** na javnoj adresi, svaki dan, u XML-u i CSV-u. Arhiva 30 dana, stabilna poveznica, REST.
- **Prima podatke iz ERP-a**: odabir datoteke → povezivanje stupaca → probni prolaz → upis.
- **Opcionalno prikazuje najnižu cijenu u 30 dana** — najmanju koju ima zabilježenu u tom prozoru.

Cijena koja izlazi u cjenik je ona **koju kupac stvarno plati** — s porezom, i sa sniženjem ako ga ima. Trgovina koja cijene unosi bez poreza dobiva bruto iznos, jer je to iznos s blagajne.

Svaka **varijanta** dobiva svoj redak, s vlastitom šifrom, cijenom, zalihom i barkodom. Naziv nosi obilježja po kojima se prepoznaje — *„Majica — Veličina: M, Boja: crna"* — jer WooCommerce varijantama ne mijenja naslov, pa bi inače više redaka nosilo isto ime uz različite cijene.

## Što ne radi

> **Ne mijenja cijene ni bilo koji drugi podatak o proizvodu.** Ni nazive, opise, kategorije ni zalihe.
>
> Piše isključivo u vlastite tablice. Obrišete li ga, trgovina je točno onakva kakva je bila prije.

Ne donosi ni poslovne odluke: gdje nađe problem, prijavi ga i objasni — ali ne odlučuje što je vaša redovna cijena, treba li ukinuti akciju, ni kako se zove vaša marka.

## Načela

**Prazno nije isto što i izostavljeno.** Polje bez vrijednosti znači „ne znamo"; izostavljen element znači „za ovaj artikl se ne primjenjuje". To su različite tvrdnje i datoteka ih razlikuje.

**Ne tvrdi ono što ne znaš.** Barkod koji strukturno ne može biti barkod ne objavljuje se. Vrijednost koju je trgovac izjavio bilježi se kao izjava, ne kao mjerenje. Najniža cijena u 30 dana računa se iz zabilježenog, a dok evidencija ne pokrije puni prozor, ekran Stanje to i piše — ograda ide trgovcu, ne šutnja kupcu.

**Pred neočekivanim se staje.** Padne li samoprovjera, jučerašnja datoteka ostaje na snazi i javi se greška. Stari cjenik je bolji od pokvarenog.

**WooCommerce je izvor istine.** Gdje on ima svoje polje — GTIN u kartici Inventar, marka u kutiji Marke — vrijedi njegova vrijednost i čita se uživo. Naša se ne kopira i ne prepisuje preko njegove.

## Sučelje

Tri ekrana i čarobnjak koji se pojavi jednom pa nestane.

| | |
|---|---|
| **Stanje** | odgovara na jedno pitanje: radi li sve. Kad radi, kratak je i dosadan. |
| **Artikli** | uvoz tablice i prikupljanje onoga što trgovina već ima. Pojedinačno se uređuje na samom proizvodu. |
| **Postavke** | referentni datum, djelatnost, podaci o trgovcu, vrijeme objave, rezerve. |
| **Napredno** | sve što ne treba svaki dan. |

Problemi se prikazuju kao **nalazi** — što je nađeno, što to znači i što učiniti — poredani po važnosti. Nalaz koji dodatak ne smije riješiti sam nudi popis i objašnjenje, bez gumba. Riješen nalaz nestaje s popisa; problem koji ne postoji se ne spominje.

## Dvije rezerve

Obje rješavaju isto: **tiho izostajanje** — stanje u kojem ništa ne javi grešku, a ono što treba objaviti ili prikazati naprosto ne postoji. Obje su postavke i obje su zadano uključene.

**Kad dnevno pokretanje zakaže, obradu guraju posjeti trgovini.** Radi nakon što je stranica već poslana, pa kupac ne čeka.

> Nije zamjena za cron i ne gasi upozorenje o njemu: dan bez ijednog posjeta ostaje bez cjenika, a posjet koji posluži predmemoriju do dodatka uopće ne dođe.

**Kad tema cijenu ispisuje na svoj način, sidrena cijena se dopisuje u pregledniku.** Filter `woocommerce_get_price_html` pokriva većinu tema, ali ne graditelje stranica koji cijenu slažu sami ni blok „All Products", koji je crta iz Store API-ja.

Skripta se učitava **samo na stranicama na kojima uobičajeni put nije radio**. Ako sve radi, nikad se ne pokrene. Ne crta ništa sama — traži od poslužitelja isti HTML koji bi ispisao i filter, pa vrijede isti uvjeti.

## Zahtjevi

WordPress 5.9+ · WooCommerce 7.0+ · PHP 7.4+

Radi na dijeljenom hostingu bez SSH-a i bez WP-CLI-ja. Duge operacije idu u pozadini, u malim komadima, s pauzom i povratom.

Za dnevnu objavu treba jedan cron redak u cPanelu — dodatak ga ispiše gotovog, preračunatog za vremensku zonu poslužitelja, i za ljeto i za zimu.

## Instalacija

1. Dodaci → Dodaj novi → Pošalji dodatak → odaberite ZIP.
2. Aktivirajte.
3. U izborniku se pojavi **Cjenik i cijene** → **Prvo postavljanje**, četiri koraka.

Detaljno u [PRVO-POSTAVLJANJE.md](PRVO-POSTAVLJANJE.md).

## Promjene

**1.3.2** — novi artikl dobiva sidrenu cijenu odmah po objavi, ne tek pri dnevnom prolazu.

**1.3.1** — ručni unos sidrene cijene više ne pomiče datum na koji se ona odnosi; referentni datum se svugdje čita iz postavki.

**1.3.0** — artikl uveden nakon referentnog datuma dobiva sidrenu cijenu: onu po kojoj je prvi put ponuđen, uz vlastiti datum. Novi dnevni posao obuhvaća artikle koje utvrđivanje nije zahvatilo.

**1.2.1** — najniža cijena u 30 dana izlazi i na varijabilnom proizvodu, uz isto pravilo kao sidrena: samo ako su sve varijante snižene i brojka im je ista.

**1.2.0** — najniža cijena u 30 dana više ne čeka punih trideset dana evidencije; prikazuje se najmanja zabilježena u prozoru, uz ogradu na ekranu Stanje dok prozor nije pun.

**1.1.2** — ekran Artikli nema više tablicu za ručni unos u nizu; podaci ulaze uvozom, prikupljanjem ili na samom proizvodu.

**1.1.1** — redak sa sidrenom cijenom je manji i bez podebljanja, veličina mu više ne ovisi o temi; ekran Stanje javlja kad je najniža u 30 dana uključena a evidencija još prazna.

**1.1.0** — dvije rezerve protiv tihog izostajanja: obradu guraju posjeti kad dnevno pokretanje zakaže; sidrena cijena se dopisuje JavaScriptom na temama koje zaobiđu uobičajeni put.

**1.0.1** — četiri popravka nađena usporedbom s drugim rješenjem: naziv varijante, cijena s porezom, oznaka za artikl bez šifre, izjava o HPOS-u.

**1.0.0** — prva verzija.

Puni popis u [readme.txt](readme.txt).

## Licenca

GPL-2.0-or-later
