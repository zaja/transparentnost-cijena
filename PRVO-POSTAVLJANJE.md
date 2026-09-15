# Prvo postavljanje

**Za koga:** za vlasnika trgovine koji je upravo instalirao dodatak i nema koga pitati.

**Koliko traje:** desetak minuta, plus čekanje na obradu koja radi sama.

**Što trebate:** pristup WordPress adminu i pristup cPanelu (za jedan jedini korak).

> **Ovaj dokument ne biste trebali trebati.** Dodatak vas vodi kroz isto to sam, ekran po ekran. Ovdje je za slučaj da želite unaprijed vidjeti što vas čeka, ili da se vratite na nešto što ste preskočili.

---

## Nakon aktivacije

U lijevom izborniku WordPressa pojavi se **Cjenik i cijene**. Kliknite.

Otvorit će se **Prvo postavljanje** — četiri koraka. Na vrhu piše dokle ste stigli.

Svaki korak prvo kaže **zašto** se nešto pita, pa tek onda pita. Ako vam objašnjenje nije jasno, to je naša greška, ne vaša — javite nam.

---

## Korak 1 — Radi li sve na vašem poslužitelju

Ekran sam provjerava tri stvari i ispisuje ih:

| Provjera | Ako nije u redu |
|---|---|
| Možemo zapisati datoteku | Zatražite od hostinga dozvolu 755 na mapi `wp-content/uploads` |
| Obrada u pozadini radi | Javite nam |
| Ništa drugo ne mijenja cijene u hodu | Piše koji dodatak i što s njim |

**Zašto se to provjerava sada:** cjenik nastaje kao datoteka i objavljuje se svaki dan sam od sebe. Bolje je da se za mjesec dana ne ispostavi da poslužitelj to ne može.

Možete nastaviti i ako nešto nije u redu — ali to treba riješiti prije nego cjenik izađe prvi put.

**Kliknite „Dalje".**

---

## Korak 2 — Dnevno pokretanje

Ovo je **jedini korak koji se ne može odraditi iz WordPressa.**

Ekran ispisuje **gotov redak**, već preračunat za vremensku zonu vašeg poslužitelja — i za ljeto i za zimu. Izgleda ovako:

```
7 4 * * * wget -q -O /dev/null "https://vasa-domena.hr/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

Kliknite u okvir — cijeli redak se označi — pa ga kopirajte.

**U cPanelu:** otvorite **Cron Jobs** → **Add New Cron Job** → zalijepite redak → spremite.

Odmah ispod naslova cPanel piše trenutno vrijeme poslužitelja. Usporedite ga s vremenom u retku. Ako se jako razlikuje, javite nam da ga preračunamo.

**Zašto:** WordPress sam po sebi ništa ne pokreće u određeni sat — čeka da netko posjeti trgovinu. Na trgovini s nekoliko posjeta dnevno to znači da cjenik izađe kad izađe.

Piše li već **„Već je postavljeno"**, preskočite ovaj korak.

**Kliknite „Dalje".**

---

## Korak 3 — Koji je vaš referentni datum

Jedno pitanje i dva datuma.

> **Prodajete li hranu, piće, kozmetiku, sredstva za čišćenje, toaletne potrepštine ili proizvode za kućanstvo?**

- **Ne prodajete** → ostavite kvadratić prazan. Cijeli katalog ima jedan datum.
- **Prodajete** → označite ga. Za te skupine vrijedi **raniji** datum, za sve ostalo kasniji.

**Zašto se to uopće pita:** uz cijenu se mora istaknuti koliko je artikl stajao na jedan određeni dan, a taj dan nije isti za sve proizvode. Trgovac koji prodaje i jedno i drugo ima **dva** datuma — i to je najčešći način da se pogriješi, jer ništa na to ne upozorava.

Datumi su već upisani. Mijenjajte ih samo ako znate da za vas vrijedi nešto drugo.

> Koji je artikl u kojoj skupini određuje se poslije, na ekranu **Artikli**. Ovdje se samo kaže imate li uopće obje.

**Kliknite „Spremi i dalje".**

---

## Korak 4 — Odakle dolaze sidrene cijene

**Ovo je jedini korak koji se ne može preskočiti.** Bez sidrenih cijena dodatak nema što prikazati ni objaviti.

Na vrhu piše za koliko artikala još ne znamo cijenu na referentni datum. Ponuđena su **tri puta** i možete ih kombinirati.

### A) Našli smo povijest cijena iz drugog dodatka

Pojavljuje se samo ako takav dodatak imate. Piše koliko zapisa, za koliko artikala, od kojeg datuma.

> **Prvo kliknite „Preuzmi kao datoteku".**
>
> Preporučujemo to bez obzira na to što odlučite dalje. Ta tablica pripada drugom dodatku — nestane li on, nestaje i ona, a ona je jedini dokaz koliko je cijena iznosila u prošlosti. **Ovo je jedini trenutak u kojem imate oboje.**

Zatim **„Preuzmi povijest u naš dodatak"**. Obrada se nastavlja u pozadini; ne morate čekati na ekranu.

Ovo je najbolji izvor, jer je cijena **izmjerena**, a ne pretpostavljena.

### B) Uvoz iz vašeg programa

Ako program koji vodi vašu robu zna izvesti cijene, otvorite **Artikli** i učitajte tablicu. Opisano niže.

### C) Nemate ništa od toga

Tada možete potvrditi da su današnje cijene ujedno i cijene s referentnog datuma.

Pročitajte rečenicu u žutom okviru prije nego označite kvadratić. Ona kaže točno što potvrđujete:

> *Potvrđujem da se cijene ovih N artikala nisu mijenjale od [datum] do danas.*

**To je vaša izjava, ne naš izračun.** Dodatak nema način provjeriti je li točna, i tako je i bilježi — s vašim imenom i datumom.

Zašto se to razdvaja: ne da bismo vas zaštitili od inspekcije, nego da za šest mjeseci postoji odgovor na pitanje **odakle ta brojka**. Nađete li poslije točniji podatak, on ovu vrijednost smije prepisati.

**Kliknite „Gotovo — otvori Stanje".**

---

## Odmah nakon toga: ekran Stanje

Ovo je ekran koji ćete otvarati ubuduće. Odgovara na jedno pitanje: **radi li sve.**

Na vrhu je jedna rečenica. Kad sve radi, piše *„Sve radi. Nema ništa za napraviti."* i ekran je kratak.

Ispod su četiri retka:

| Redak | Što znači |
|---|---|
| **Cjenik** | je li objavljen danas, u koliko sati, koliko artikala |
| **Javna adresa** | adresa cjenika — kliknite u okvir da se označi, pa kopirajte |
| **Sidrena cijena** | na koliko se artikala prikazuje |
| **Dnevno pokretanje** | radi li ono što ste postavili u koraku 2 |

### Prvi put cjenik nije objavljen

To je uredno — još ga nitko nije napravio. Pojavit će se nalaz **„Cjenik još nije objavljen"** s gumbom **„Objavi sada"**. Kliknite ga.

Obrada ide u pozadini, u malim komadima. Osvježite ekran za minutu.

### Nalazi

Ispod statusnih redaka stoji **„Što treba riješiti"** — popis stvari poredanih po važnosti:

- **zaustavlja** — bez toga cjenik ne izlazi
- **važno** — cjenik izlazi, ali nešto u njemu nije potpuno ili točno
- **savjet** — vrijedi napraviti

Svaki nalaz kaže **što je nađeno, što to znači** i **što učiniti**. Neki imaju gumb koji to riješi odmah. Neki nemaju — jer je odluka vaša, a ne naša. Kod njih piše zašto.

**Riješen nalaz nestaje s popisa.** Problem koji ne postoji se ne spominje.

---

## Ekran Artikli

Otvorite ga kad nalaz kaže da podaci nisu potpuni.

### Uvoz iz tablice — gore, i to je namjerno

Barkodovi, marke i količine obično već postoje u programu koji vodi vašu robu. Izvezite ih kao CSV i učitajte ovdje. Ide u tri koraka:

**1. Učitaj datoteku.** Čitamo samo zaglavlje, ništa se ne upisuje.

**2. Povežite stupce.** Vaš program izvozi stupce svojim imenima — `EAN`, `Brend`, `Šifra artikla` — i to je u redu. Recite nam koji je koji. Ono što prepoznamo već je odabrano; pored svakog piše prvi redak iz vaše datoteke da vidite jeste li pogodili.

**3. Pogledajte što bi se promijenilo.** Prije upisa dobivate sažetak i popis redaka koji **neće** proći, sa razlogom za svaki.

Tek onda **„Upiši N redaka"**.

Nekoliko pravila:

- **Prazna ćelija znači „ne diraj", ne „obriši".** Tablica od dobavljača nosi samo ono što on zna.
- **Nula u stupcu cijene se odbija**, s objašnjenjem. Nula znači „artikl je besplatan", prazno znači „ne znamo" — to su različite tvrdnje.
- **Brojevi se čitaju u bilo kojem obliku**: `12,50`, `1.234,56`, `1,234.56`, `12,50 EUR`.
- **Uvoz ne prepisuje ono što je jače.** Vrijednost koju ste upisali rukom ili koja je izmjerena iz zapisa o cijenama ostaje.

Nemate tablicu? **„Preuzmi predložak s vašim artiklima"** daje CSV s vašim šiframa i praznim stupcima — popunite i vratite istim putem.

**„Pokupi što trgovina već ima"** traži barkode, marke i količine koje su već negdje u WooCommerceu. Ništa ne prepisuje.

### Ručna dopuna — dolje

Za pojedinačne artikle i ispravke. Poredano po prometu, pa ako stanete na pola, stali ste na pravom mjestu.

U svakom retku stoji poveznica **„otvori proizvod"** koja vodi ravno na taj artikl.

---

## Gdje su polja na samom proizvodu

Otvorite bilo koji proizvod. Uz kartice **Općenito**, **Inventar** i **Otprema** pojavila se i:

> ### Podaci za cjenik

Ondje su barkod, marka, neto količina, oblik prodaje i oznaka „ne odnosi se" po polju — dakle i ono čega na ekranu Artikli nema. Kod varijabilnih proizvoda ista polja stoje uz svaku varijantu.

**Redoslijed koji preporučamo:**

| Koliko artikala | Čime |
|---|---|
| tisuće | **uvoz** — ekran Artikli, gore |
| desetak | ekran Artikli, ručna dopuna dolje |
| jedan, dok ga ionako uređujete | **kartica na proizvodu** |

### Barkod i marka: WooCommerce ima prednost

WooCommerce od svoje verzije 9 ima **vlastita polja** za oboje: GTIN u kartici **Inventar**, marku u kutiji **Marke** uz proizvod.

> **Ako su ta polja popunjena, vrijedi ono što u njima piše.** Naše polje tada pokazuje tu vrijednost, ne da se uređivati, i ima poveznicu na mjesto gdje se mijenja.
>
> **Ako su prazna**, možete upisati kod nas. Ali pravo mjesto je WooCommerce — popunite li ga poslije ondje, naša vrijednost se briše i vrijedi njegova. To piše ispod svakog takvog polja, da ne bude iznenađenje.

**Barkod koji ne može biti barkod ne objavljujemo.** Nijedan GTIN nema dvije ili tri znamenke — objaviti takvu vrijednost značilo bi tvrditi nešto neistinito. Polje u cjeniku ostaje prazno, a na ekranu Stanje pojavi se nalaz s popisom.

Barkod ispravne duljine koji pada na kontrolnoj znamenki **objavljujemo** — moguće je da je broj pravi a jedna znamenka krivo prepisana. I o njemu javimo, da ga provjerite s pakiranjem.

Vrijednost se **nikad ne kopira**. Čitamo je u trenutku prikaza i u trenutku objave, pa ne može zastarjeti.

Isto vrijedi za uvoz i za skupno uređivanje: **ako WooCommerce već ima barkod ili marku, ne prepisuju se.** Nakon uvoza to piše kao zasebna brojka, ne skriveno pod „upisano".

**Marka kod varijacija:** marka se u WooCommerceu postavlja na proizvodu, ne po varijanti — i tako je ispravno, jer su majica S i majica XXL iste marke. Cjenik je traži za svaki redak, pa je varijante naslijede od proizvoda. Na varijanti zato piše da dolazi s proizvoda.

**„Ne odnosi se"** je za ono što artikl objektivno nema: majica vlastite proizvodnje nema barkod jer joj ga nitko nije dodijelio. **Traži razlog** — oznaka bez razloga nije odluka nego pogađanje. Razlog, vaše ime i datum ostaju zapisani.

---

## Ekran Postavke

Otvorite ga rijetko. Sve ima zadanu vrijednost koja radi.

| Postavka | Kad je dirati |
|---|---|
| Referentni datum | ako ste u koraku 3 odgovorili krivo |
| Djelatnost | ako počnete prodavati nešto iz reguliranih skupina |
| Najniža cijena u 30 dana | vidi niže |
| Podaci o trgovcu | ulaze u ime objavljene datoteke |
| Vrijeme dnevne objave | ako vam rok nije 8:00 |
| Rezerve | dvije, obje zadano uključene — vidi niže |

### Rezerve

Dvije stvari rade u pozadini kad uobičajeni put zakaže. Ostavite ih uključene osim ako imate razlog.

**„Ako dnevno pokretanje zakaže, neka obradu gurnu posjeti trgovini."** Kad rok prođe a cjenik nije izašao, idući posjeti ga guraju — nakon što je stranica već poslana, pa kupac ne čeka.

> **To nije zamjena za cron.** Dan bez ijednog posjeta ostaje bez cjenika, a posjet koji posluži predmemoriju do nas uopće ne dođe. Zato nalaz o nepostavljenom pokretanju ostaje i dok ovo radi.

**„Dopiši sidrenu cijenu i ondje gdje je tema nije ispisala."** Neke teme i graditelji stranica cijenu crtaju na svoj način, pa je ne možemo dopuniti uobičajenim putem — tada se dopisuje u pregledniku.

Učitava se **samo na stranicama na kojima uobičajeni put nije radio.** Ako sve radi, ovo se nikad ne pokrene i ne košta ništa.

### Najniža cijena u 30 dana

Imate li već drugi dodatak koji to prikazuje, **naš prikaz je zadano isključen** — i piše koji je to dodatak. Kupac koji vidi dvije tvrdnje o istoj stvari ne zna kojoj vjerovati, a to je gore nego nijedna.

Uključite li je, prikazuje se tek kad naša evidencija bude dovoljno duboka. Piše i koliko dana treba čekati. **Dodatak instaliran prije deset dana ne smije tvrditi da zna najnižu u trideset** — to nije približno točno nego netočno.

Imate li stariju povijest iz drugog dodatka, uvoz je skraćuje na nulu.

---

## Ekran Napredno

Otvorite ga samo ako nešto ne radi, ili ako vam mi tako kažemo.

| Stavka | Čemu |
|---|---|
| Obrada u pozadini | što se trenutno obrađuje, zapisnici, ponovno pokretanje i poništavanje |
| Dijagnostika | jedanaest provjera na vašem poslužitelju |
| Odakle dolazi svaka sidrena cijena | razrada po izvorima: izmjereno, uvezeno, upisano rukom, vaša izjava |
| Ponovi prvo postavljanje | ista četiri koraka; ništa ne briše |

---

## Što vidite svaki dan

**U pravilu ništa.** Dodatak radi sam: objavi cjenik prije roka, pohvata promjene cijena, počisti staru arhivu.

Otvorite **Stanje** kad vas zanima radi li. Piše li *„Sve radi"*, radi.

---

## Što točno izlazi u cjeniku

Popis podataka propisan je (NN 101/2026). Dodatak ga ne bira i ne dodaje mu svoje:

| Podatak | Odakle |
|---|---|
| naziv, šifra | iz proizvoda |
| marka | iz podataka o proizvodu — vidi ekran Artikli |
| jedinica mjere · cijena za jedinicu mjere | **samo za robu koja se mjeri.** Za robu koja se prodaje po komadu ta dva podatka se izostavljaju, jer se ne primjenjuju |
| maloprodajna cijena | cijena koju kupac plaća |
| je li iz posebnog oblika prodaje · naziv tog oblika | „da"/„ne", pa naziv — vidi niže |
| sidrena cijena | ono što ste postavili u koraku 4 |
| barkod | iz podataka o proizvodu; izlazi samo ako prođe provjeru |
| dostupno / nedostupno | iz stanja zaliha u WooCommerceu |

**Roba „na čekanju" izlazi kao dostupna.** Kupac je može naručiti i bit će mu naplaćena — trgovina je aktivno prodaje. Označiti je kao nedostupnu značilo bi objaviti da se ne prodaje nešto što se prodaje.

### Kako se zove vaša akcija

Naziv posebnog oblika prodaje je **obvezan podatak**, a nije uvijek „akcija": **rasprodaja** i **sezonsko sniženje** pravno su nešto drugo.

Dodatak zna utvrditi **da** se artikl prodaje ispod redovne cijene — to je činjenica iz cijena. Ne zna kako se to kod vas zove, i ne pogađa: bez vašeg izbora piše **„akcija"**, jer je to definicija prodaje po nižoj cijeni i najčešći slučaj.

Mijenja se na dva mjesta:

- **na stranici proizvoda i svake varijacije** — polje se pojavljuje samo dok se artikl stvarno prodaje ispod redovne cijene;
- **skupno, na ekranu Artikli** — panel *„Kako se zove vaša akcija"* s popisom svih takvih artikala, cijenom, trajanjem i izbornikom. Ima i „za označene postavi". Panel se ne pojavljuje ako takvih artikala nemate.

> **Izbor vrijedi za tekuće razdoblje, ne zauvijek.** Promijenite li cijenu, počinje novo razdoblje i vraća se na zadano. Isti artikl može u ožujku biti na akciji, a u prosincu na rasprodaji — ne morate se sjetiti da staru oznaku povučete.

**Ako označite sezonsko sniženje**, upozorit ćemo vas kad traje dulje od **60 dana** ili kad je **treće** u kalendarskoj godini. To su jedine brojke u ovom području koje dolaze iz propisa, a ne iz tumačenja.

Upozorenje je **upozorenje, ne zapreka.** Prekoračenje može imati razlog koji mi ne vidimo — nećemo vas spriječiti.

---

**Neto količina i kategorija proizvoda nisu obvezne.** I dalje ih možete unositi i i dalje su korisne — količina određuje hoće li se objaviti cijena po jedinici mjere — ali njihov izostanak se ne broji kao manjak.

> Iznimka: **ako prodajete hranu, piće, kozmetiku, sredstva za čišćenje, toaletne potrepštine ili proizvode za kućanstvo**, kategorija vam i dalje treba. Ne zbog cjenika, nego zato što određuje koji od dva referentna datuma vrijedi za koji artikl. Bez nje bi dio kataloga dobio pogrešan datum, a ništa vas ne bi upozorilo.

---

## Što dodatak neće napraviti

**Ne mijenja vaše cijene.** Ni jednu, nikad. Ne mijenja ni nazive, opise, kategorije ni zalihe. Ni jednu, nikad. Piše isključivo u vlastite tablice; WooCommerce ostaje točno onakav kakav je bio. Obrišete li dodatak, ne ostaje nikakva šteta.

Iz toga slijedi i ovo: **odluke o cijenama ostaju vaše.** Gdje nađemo problem, prijavimo ga i objasnimo — ali ne odlučujemo umjesto vas što je vaša redovna cijena, treba li ukinuti akciju, ni kako se zove vaša marka.

**Ne vidi cijene napisane u tekst.** Baneri, opisi proizvoda, odredišne stranice — ondje dodatak ne zna što piše. Ako u opisu stoji „–50 %", to ostaje ondje i kad se cijena promijeni.
