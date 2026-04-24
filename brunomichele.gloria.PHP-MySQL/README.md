# Portfolio Rebalancing Web App

This project is a **personal and academic** web application developed for educational purposes, as part of a school assignment. It demonstrates the use of **XML**, **HTML**, **CSS**, **JavaScript**, and **DOM manipulation** to manage and visualize a financial portfolio. The app includes functionalities such as:

- Representing a portfolio in XML  
- Validating the XML structure with DTD/XSD  
- Displaying data in an HTML table  
- Calculating target deviations and rebalance actions  
- Simulating asset purchases and sales  

## ⚠️ Disclaimer

- This project is **not intended for real-world financial planning or investment decisions**  
- The app **does not guarantee accuracy or reliability** of the data it uses or displays  
- No financial data is stored permanently; no database is used  
- Asset prices are obtained in real-time (or near real-time) from public APIs for demonstration purposes only  
- The calculations and results provided are **simplified simulations** and **should not be interpreted as financial advice**  

## Legal Notice

This software is provided “as is”, **without any warranty of any kind**, express or implied, including but not limited to warranties of merchantability, fitness for a particular purpose, or non-infringement. In no event shall the author(s) or contributors be held liable for any claim, damages, or other liability, whether in an action of contract, tort, or otherwise, arising from, out of, or in connection with the software or the use or other dealings in the software.

**Use of this software is at your own risk.**

## License

No license. This is a private academic project.  
**Not intended for reuse or redistribution.**

---

## NOTE:

-Autore: Bruno Michele Gloria

-Esercizi di riferimento: Anche in questo caso non ho fatto riferimento a un esercizio specifico delle slide. Ho utilizzato gli strumenti presentati a lezione (PHP, MySQL, gestione form, sessioni, PDO) integrandoli in un progetto unico più strutturato.

-Principi utilizzati: Il progetto estende il precedente lavoro basato su XML sostituendo il file con un database relazionale MySQL.
Ho progettato uno schema EER e la relativa traduzione logica per gestire portafogli finanziari, bucket gerarchici e asset.
L’applicazione utilizza PHP con PDO per l’accesso al database, prepared statements per la sicurezza, e sessioni per la gestione degli utenti.
È presente uno script di installazione (install.php) che permette di creare e inizializzare automaticamente il database.

-Installazione e configurazione:
Per installare il progetto è sufficiente:

1. Modificare il file lib/dati_generali.php inserendo:

   * credenziali DBMS
   * nome database
   * utente e password demo

2. Aprire da browser il file:
   install.php

3. Inserire le credenziali amministrative MySQL richieste dalla pagina.

L’installazione crea database, tabelle e dati demo.

Dopo l’installazione è possibile accedere da:
index.php
utilizzando le credenziali demo definite in dati_generali.php.

P.S. non ho modo di testare su computer diversi dal mio, spero funzioni tutto.

-Funzionalità ed utilizzo:
L’applicazione permette di creare e gestire portafogli organizzati in cartelle, definire bucket gerarchici e inserire asset (azioni, ETF, obbligazioni).
È possibile registrare operazioni di acquisto e vendita e visualizzare lo stato del portafoglio.
È implementato un algoritmo di ribilanciamento che calcola automaticamente le operazioni necessarie per riallineare il portafoglio ai target definiti, tenendo conto di tolleranza, commissioni, tassazione e vincoli sulle quantità.
Una volta effettuato il ribilanciamento è possibile visualizzare le flags generate dall'algoritmo per il singolo asset 'hoverando' la relativa cella deltaQty (hanno nomi leggermente indecifrabili, ma lasciano un'idea sufficiente).

-Github: [https://github.com/brunomichelegloria/lweb/tree/main/brunomichele.gloria.PHP-MySQL](https://github.com/brunomichelegloria/lweb/tree/main/brunomichele.gloria.PHP-MySQL)