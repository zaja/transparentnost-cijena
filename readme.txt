=== Cjenovna transparentnost ===
Contributors: goranzajec
Donate link: https://svejedobro.hr
Tags: woocommerce, cijene, cjenik, transparentnost
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 11.1
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Uskladenje WooCommerce trgovine s propisima o kontroli cijena: dnevna objava
cjenika i isticanje dodatne (sidrene) cijene.

== Description ==

= Ne dira vase cijene =

Dodatak ne mijenja cijene ni bilo koji drugi podatak o proizvodu — ni nazive,
opise, kategorije ni zalihe. Pise iskljucivo u vlastite tablice. Obrisete li ga,
trgovina je tocno onakva kakva je bila prije.

Ono sto radi: cita cijene, vodi evidenciju o njima i objavljuje datoteku koju
propis trazi.

= Zasto dodaje karticu na proizvod =

Propis trazi podatke koje WooCommerce ne vodi za svaki zapis — barkod po
varijanti, marku uz svaki redak, vrstu snizenja. Kartica "Podaci za cjenik" na
proizvodu postoji da ih ima gdje upisati, ne da mijenja kako trgovina radi.

Gdje WooCommerce ima svoje polje (GTIN u kartici Inventar, marka u kutiji
Marke), vrijedi njegova vrijednost i cita se uzivo. Nasa se ne kopira i ne
prepisuje preko njegove.

Dodatak uz cijenu proizvoda istice dodatnu cijenu na referentni datum i, kod
snizenja, najnizu cijenu u posljednjih 30 dana. Vodi vlastitu evidenciju cijena
da bi te podatke mogao izracunati, a ne pretpostaviti.

= Opseg =

**Dodatak radi iskljucivo s cijenama unesenima u proizvod.** Cijene utipkane u
sadrzaj stranica — baneri, elementi graditelja stranica, opisi proizvoda,
odredisne stranice — odgovornost su vlasnika trgovine. Dodatak ih ne dira, ne
trazi i ne moze za njih jamciti.

Primjer: opis artikla u kojem rukom pise "-50%" ili "posebna ponuda". Ta
tvrdnja o snizenju ne dolazi iz cijene artikla nego je utipkana u tekst, pa
dodatak o njoj ne zna nista. Ostaje na stranici i kad se cijena promijeni.

Ako negdje na stranicama stoji rucno upisana cijena, treba je uskladiti rucno.

= Sve operacije rade iz administracije =

Dodatak ne zahtijeva pristup terminalu. Dugi poslovi rade u pozadini, u malim
komadima, da ne opterete posluzitelj na dijeljenom hostingu.

Za dnevnu objavu treba jedan redak u cPanelu. Dodatak ga ispise gotovog,
preracunatog za vremensku zonu posluzitelja — i za ljeto i za zimu. Ako do
njega ne dodete, objavu guraju posjeti trgovini, ali to nije zamjena: dan bez
ijednog posjeta ostaje bez cjenika.

= Cijene s porezom =

U cjenik izlazi cijena koju kupac stvarno plati. Trgovina koja cijene unosi bez
poreza dobiva u datoteci bruto iznos, jer je to iznos s blagajne — a ne neto
iznos iz polja.

= Varijante =

Svaka varijanta dobiva svoj redak, s vlastitom sifrom, cijenom i zalihom.
Naziv nosi obiljezja po kojima se prepoznaje ("Majica — Velicina: M, Boja:
crna"), jer WooCommerce varijantama ne mijenja naslov pa bi inace vise redaka
nosilo isto ime uz razlicite cijene.

Barkod se vodi po varijanti, marka po proizvodu — kako je i u WooCommerceu.

== Installation ==

1. Dodaci > Dodaj novi > Posalji dodatak i odaberite ZIP.
2. Aktivirajte.
3. U izborniku se pojavi "Cjenik i cijene" > "Prvo postavljanje", cetiri koraka.

Dodatak ne mijenja nijedan podatak o proizvodu, pa ga je sigurno aktivirati i
na trgovini koja radi.

== Frequently Asked Questions ==

= Mijenja li dodatak moje cijene? =

Ne. Ni jednu, nikad. Ne mijenja ni nazive, opise, kategorije ni zalihe. Pise
iskljucivo u vlastite tablice. Obrisete li ga, trgovina je tocno onakva kakva
je bila prije.

= Zasto mi dodaje karticu na proizvod? =

Propis trazi podatke koje WooCommerce ne vodi za svaki zapis — barkod po
varijanti, marku uz svaki redak, vrstu snizenja. Kartica "Podaci za cjenik"
postoji da ih ima gdje upisati.

Gdje WooCommerce ima svoje polje (GTIN u kartici Inventar, marka u kutiji
Marke), vrijedi njegova vrijednost i cita se uzivo. Nasa se ne kopira i ne
prepisuje preko njegove.

= Trebam li cron? =

Da, ako zelite jamciti rok. Bez njega objavu guraju posjeti trgovini, ali dan
bez ijednog posjeta ostaje bez cjenika, a posjet koji posluzi predmemoriju do
dodatka uopce ne dode. Dodatak na to upozorava dok redak nije postavljen.

= Imam vec dodatak koji prikazuje najnizu cijenu u 30 dana =

Tada je nas prikaz zadano iskljucen. Kupac koji vidi dvije tvrdnje o istoj
stvari ne zna kojoj vjerovati, a to je gore nego nijedna.

= Sidrena cijena mi se ne prikazuje =

Ako tema cijenu ispisuje na svoj nacin, uobicajeni put je ne moze dopuniti.
Tada se dopisuje u pregledniku — postavka "Dopisi sidrenu cijenu i ondje gdje
je tema nije ispisala", zadano ukljucena.

Ako artikla nema u evidenciji, prikaza nema ni na koji nacin. To pise na ekranu
Stanje, s popisom.

== Changelog ==

= 1.1.0 =
* Kad dnevno pokretanje zakaze, obradu guraju posjeti trgovini — nakon sto je
  stranica poslana, pa kupac ne ceka. Nije zamjena za cron i ne gasi upozorenje
  o njemu.
* Sidrena cijena se dopisuje JavaScriptom na temama koje cijenu ispisuju mimo
  uobicajenog puta, i u bloku "All Products". Ucitava se samo na stranicama na
  kojima uobicajeni put nije radio.
* Obje rezerve su postavke, obje zadano ukljucene.

= 1.0.1 =
* Naziv varijante nosi obiljezja po kojima se prepoznaje. Ranije je vise redaka
  nosilo isto ime uz razlicite cijene — na testnom katalogu 55 % datoteke.
* U cjenik izlazi cijena S POREZOM. Trgovina koja cijene unosi bez poreza
  ranije bi objavila neto iznos, a kupac placa bruto.
* Artikl bez sifre dobiva oznaku umjesto praznog obveznog polja.
* Izjava o kompatibilnosti s HPOS-om i blokovima kosarice.

= 1.0.0 =
* Prva verzija. Sidrena cijena uz cijenu, dnevna objava cjenika u .xml i .csv,
  uvoz iz ERP-a, carobnjak za prvo postavljanje.
