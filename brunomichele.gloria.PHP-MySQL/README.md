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

NOTE:
  -Autore: Bruno Michele Gloria

  -Esercizi di riferimento: Nuovamente mi scuso per non avere degli espliciti esercizi a cui ho fatto riferimento. Ho semplicemente cercato di usare gli strumenti mostrati a lezione e nelle slide, per poi imparare nuovi strumenti quando necessario.

  -Principi utilizzati: In questo progetto l’obiettivo era dimostrare l’utilizzo del modello DOM (Document Object Model) per la manipolazione di un file XML contenente dati strutturati.
  Ho sviluppato un’applicazione per la gestione e il ribilanciamento di un portafoglio finanziario, così da poter applicare in modo variegato le operazioni di lettura, modifica e salvataggio di dati XML.
  Il file XML è validato tramite DTD, mentre il DOM è stato utilizzato sia in PHP, per elaborare e aggiornare il file, sia in JavaScript, per leggere e interagire con la pagina HTML generata dinamicamente dal server.

  -Path richiesto dai file: Se non ho commesso errori dovrebbe bastare spostare la cartella dell'homework all'interno di una qualsiasi locazione del localhost. Ho provato a renderlo indipendente dal path usando le variabili globali di sistema. D'altra parte, non so come testare efficacemente la correttezza senza provare su un altro dispositivo.

  -Funzionalità ed utilizzo: index.php si occupa di gestire un piccolo e limitato filesystem così da rendere possibile visualizzare, aggiungere, rimuovere i file contenenti i portafogli, olre che importare un precedente file di backup generato successivamente ad una qualunque modifica in un portafoglio. Accedendo ad un portafoglio viene inviata la pagina gestionalePortafoglio.php, che fornisce un elenco degli asset in portafoglio ed una piccola analisi del loro stato (la percentuale occupata in portafoglio), mostrata anche tramite grafico a torta. I prezzi degli asset vengono rischiesti a yahooFinance quando possibile, altrimenti viene fatto scraping da alcune pagine (motivo per cui i dati nel file .xml sono limitati, lo scraping comporta dei limiti a cosa posso salvare per ragioni legali); questi prezzi vengono poi salvati in una variabile di sessione così da limitare il numero di chiamate a questi servizi.
  Le operazioni disponibili sono: aggiunta, rimozione e modifica di asset o bucket (i buckets sono l'equivalente di cartelle di asset) tramite modifyAssets.php, aggiunta di operazioni di acquisto o vendita, e un operazione di cumulazione la quale converte lo storico delle operazioni passate in un'unica operazione dal valore fiscale equivalente tramite addOps.php, il calcolo delle operazioni necessarie al ribilanciamento del portafoglio tramite rebalance.php. Nei primi due file uso DOM per accedere direttamente al file XML, ed eseguire le azioni richieste una alla volta; nell'ultimo ho preferito usare usarlo per leggere l'interità del file e creare un albero con i suoi contenuti.
  Come richiesto alla consegna del precedente compito ho integrato in questo la parte mancante sull'utilizzo dei form, utilizzando i metodi POST per le operazioni e GET per le comunicazioni tra le pagine.
  
  -Github: https://github.com/brunomichelegloria/lweb/tree/main/brunomichele.gloria.XML-DOM
  
