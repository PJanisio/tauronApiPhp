# TauronApiPhp (e-licznik)

Skrypt `index.php` loguje się do serwisu **Tauron eLicznik**, wybiera wskazany licznik i zwraca dane zużycia / produkcji energii w formacie JSON.

## Wymagania

- **PHP**: ≥ `8.1` (testowane na wersji 8.4.8)
- **cURL**
- Dostęp do zapisu w katalogu, w którym znajduje się `index.php`

## Parametry GET

| Parametr      | Wymagany                 | Opis |
|---------------|--------------------------|------|
| `user`        | ✔                        | login (e-mail do eLicznik) |
| `pass`        | ✔                        | hasło do eLicznik |
| `meter`       | ✔                        | numer punktu poboru (PP) / licznik |
| `type`        | ✔                        | `consumption` (pobór) lub `generation` (produkcja PV) |
| `balanced`    | ✖                        | `0` = dane surowe (domyślnie), `1` = bilansowanie godzinowe (netto) |
| `period`      | ✖                        | `range` (domyślnie) \| `monthly` \| `yearly` \| `last_12_months` |
| `from`        | ✔ gdy `period=range`     | data początkowa (`YYYY-MM-DD` albo `DD.MM.YYYY`) |
| `to`          | ✔ gdy `period=range`     | data końcowa (`YYYY-MM-DD` albo `DD.MM.YYYY`) |
| `month`       | ✖ gdy `period=monthly`   | `YYYY-MM` (domyślnie: bieżący miesiąc) |
| `year`        | ✖ gdy `period=yearly`    | `YYYY` (domyślnie: bieżący rok) |
| `total_only`  | ✖                        | `1` = zwróć **jedną** sumaryczną wartość |
| `save`        | ✖                        | `1` = zapis do pliku JSON  |

## Przykładowe zapytania

Pobieranie danych z zakresu 10-15 sierpnia 2025, pobór energi po zbilansowaniu, raport jako json oraz zapis do pliku:

```json
https://twoja_domena.pl/index.php?user=xxx@gxxxcom&pass=xxxxxxxx&meter=590322xxxxxxxxxx&from=2025-08-10&to=2025-08-15&type=consumption&balanced=1&save=1
```

Miesięcznie – suma za sierpień 2025 (bilansowanie)

```json
https://twoja_domena.pl/index.php?user=USER&pass=PASS&meter=PP&type=generation&balanced=1&period=yearly&year=2025&total_only=1
```

Ostatnie 12 miesięcy - tylko suma (obejmuje bieżący miesiąc)

```json
https://twoja_domena.pl/index.php?user=USER&pass=PASS&meter=PP&type=consumption&balanced=0&period=last_12_months&total_only=1
```

## Wynik pełny

```json
{
  "status": "ok",
  "where": "data",
  "how": "consumption_balanced",
  "input": {
    "user": "pa***",
    "meter": "5903...",
    "type": "consumption",
    "balanced": 1,
    "from": "2025-08-10",
    "to": "2025-08-15"
  },
  "attempts": [ /* metadane prób/źródeł */ ],
  "data": {
    "success": true,
    "data": {
      "allData": [
        { "Date": "2025-08-10", "Hour": "00", "EC": "0.45", "Zone":"1","ZoneName":"Cała doba","Taryfa":"G11" }
      ],
      "sum": 34.5,
      "zones": { "1": 34.5 },
      "tariff": "G11"
    }
  }
}
```

## Wynik kompaktowy (tylko suma)

```json
{
  "status": "ok",
  "where": "data",
  "how": "generation_balanced",
  "period": "yearly",
  "input": {
    "meter": "5903...",
    "type": "generation",
    "balanced": 1,
    "from": "2025-01-01",
    "to": "2025-12-31"
  },
  "value": 1234.56
}
```

## Zwracane kody HTTP

| Kod | Kiedy | Przykładowe `where` | Uwagi |
|-----|-------|----------------------|-------|
| **200 OK** | Sukces – dane pobrane lub zsyntetyzowane | `data` | Pole `data` zawiera strukturę z `success: true`. |
| **400 Bad Request** | Błąd wejścia: brak wymaganych parametrów, zły format daty, niepoprawny `type` / `balanced` | `inputs` | Przykład: „Invalid date format. Use YYYY-MM-DD or DD.MM.YYYY”. |
| **500 Internal Server Error** | Awaria kodowania odpowiedzi JSON (rzadkie) | `encode` | W bloku `catch` ustawiany jest kod 500. |
| **502 Bad Gateway** | Problem po stronie serwisu źródłowego: nieudane logowanie/wybór licznika lub brak danych z endpointów | `login`, `select_meter`, `fetch` | Skrypt korzysta z nieoficjalnych endpointów – ich niedostępność skutkuje 502. |

## Znane ograniczenia

- Brak oficjalnego API – skrypt używa wewnętrznych endpointów serwisu eLicznik, które mogą się zmienić bez zapowiedzi.

- Możliwość blokady – częste odpytywanie (np. co minutę) może skutkować blokadą konta lub sesji.

- Niepełne dane – czasem dane z ostatnich godzin lub dni są opóźnione albo niedostępne.

- Brak wsparcia Taurona – używasz na własne ryzyko, operator nie zapewnia pomocy technicznej dla tego typu integracji.
