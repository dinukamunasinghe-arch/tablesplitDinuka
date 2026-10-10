import { useState } from 'react';
import { queueKitchen, readError, validationLines } from '../services/api';

const emptyTicket = {
  id: '',
  table: '',
  created: '',
  prep: '',
  priority: '',
};

export default function KitchenQueue() {
  const [now, setNow] = useState('');
  const [draft, setDraft] = useState(emptyTicket);
  const [tickets, setTickets] = useState([]);
  const [loading, setLoading] = useState(false);
  const [validation, setValidation] = useState(null);
  const [failure, setFailure] = useState('');
  const [result, setResult] = useState(null);

  function updateDraft(field, value) {
    setDraft((current) => ({ ...current, [field]: value }));
  }

  function addTicket(event) {
    event.preventDefault();
    setTickets((current) => [...current, draft]);
    setDraft(emptyTicket);
  }

  function removeTicket(index) {
    setTickets((current) => current.filter((_, ticketIndex) => ticketIndex !== index));
  }

  async function sendQueue(event) {
    event.preventDefault();
    setValidation(null);
    setFailure('');

    if (now.trim() === '') {
      setFailure('Enter the current time.');
      return;
    }

    setLoading(true);

    const payload = {
      now: Number(now),
      tickets: tickets.map((ticket) => {
        const row = {
          id: ticket.id.trim(),
          table: Number(ticket.table),
          created: Number(ticket.created),
          prep: Number(ticket.prep),
        };

        if (ticket.priority) {
          row.priority = ticket.priority;
        }

        return row;
      }),
    };

    try {
      const response = await queueKitchen(payload);
      setResult(response);
    } catch (error) {
      const apiError = readError(error);
      setValidation(apiError.validation);
      setFailure(apiError.message);
      setResult(null);
    } finally {
      setLoading(false);
    }
  }

  const messages = validationLines(validation);

  return (
    <section className="card kitchen">
      <h2>Kitchen queue</h2>

      <label>
        Current time
        <input
          type="number"
          min="0"
          step="any"
          value={now}
          onChange={(event) => setNow(event.target.value)}
        />
      </label>

      <form className="stack" onSubmit={addTicket}>
        <div className="fields">
          <label>
            Ticket ID
            <input
              value={draft.id}
              onChange={(event) => updateDraft('id', event.target.value)}
              required
            />
          </label>
          <label>
            Table
            <input
              type="number"
              step="1"
              value={draft.table}
              onChange={(event) => updateDraft('table', event.target.value)}
              required
            />
          </label>
          <label>
            Created
            <input
              type="number"
              min="0"
              step="any"
              value={draft.created}
              onChange={(event) => updateDraft('created', event.target.value)}
              required
            />
          </label>
          <label>
            Preparation
            <input
              type="number"
              min="0"
              step="any"
              value={draft.prep}
              onChange={(event) => updateDraft('prep', event.target.value)}
              required
            />
          </label>
          <label>
            Priority
            <select
              value={draft.priority}
              onChange={(event) => updateDraft('priority', event.target.value)}
            >
              <option value="">Normal</option>
              <option value="first">First</option>
              <option value="VIP">VIP</option>
            </select>
          </label>
        </div>
        <button type="submit">Add ticket</button>
      </form>

      {tickets.length === 0 ? (
        <p className="muted">No tickets added yet.</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Ticket</th>
                <th>Table</th>
                <th>Created</th>
                <th>Prep</th>
                <th>Priority</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {tickets.map((ticket, index) => (
                <tr key={`${ticket.id}-${index}`}>
                  <td>{ticket.id}</td>
                  <td>{ticket.table}</td>
                  <td>{ticket.created}</td>
                  <td>{ticket.prep}</td>
                  <td>{ticket.priority || 'Normal'}</td>
                  <td>
                    <button type="button" className="text-button" onClick={() => removeTicket(index)}>
                      Remove
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <form onSubmit={sendQueue}>
        <button type="submit" disabled={loading || tickets.length === 0}>
          {loading ? 'Sending tickets...' : 'Send to kitchen'}
        </button>
      </form>

      {loading ? <p className="muted">Sending tickets...</p> : null}
      {failure ? <p className="errors">{failure}</p> : null}
      {messages.length > 0 ? (
        <ul className="errors">
            {messages.map((line) => (
              <li key={`${line.field}-${line.message}`}>{line.message}</li>
            ))}
        </ul>
      ) : null}

      {result ? (
        <div className="queue-result">
          <h3>Cooking order</h3>
          <p className="order">{result.order.join(' → ')}</p>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Step</th>
                  <th>Ticket</th>
                  <th>Table</th>
                  <th>Priority</th>
                  <th>Started</th>
                  <th>Waiting</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                {result.selections.map((selection) => (
                  <tr key={selection.id}>
                    <td>{selection.step}</td>
                    <td>{selection.id}</td>
                    <td>{selection.table}</td>
                    <td>{selection.priority || 'Normal'}</td>
                    <td>{selection.started}</td>
                    <td>{selection.waiting}</td>
                    <td>{selection.reason}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}
    </section>
  );
}
