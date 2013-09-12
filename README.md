AsyncPHP
========

This is a simple class to enable asynchronous/parallel processing within PHP. It accomplishes this using HTTP streams to run scripts in parallel.

## Usage

The basic premise is that an object of this class is setup in the master process. The master process adds child tasks to a queue then starts the queue; the queue manages running these tasks as child processes in parallel and then returns to the master process. Each task is setup as a PHP script on the local machine that can be asked arguments via a GET query string.

The class construct statement includes optional configuration variables: max_number_of_jobs sets how many tasks can be running at any given time; min_load sets the maximum system load (using system load average for previous minute, like you'd see with the `uptime` command) you can run at; timeout sets how many seconds before timeout (or 0 for no timeout); log sets the output/logging method.

The function AddJobToQueue is used to queue up a task. Once all the required tasks are queued, the function StartQueue() is called. It will return the log/output string when the child processes have all finished or when timeout is reached.

## Contributing

Contributions are welcome and invited. This is very rough around the edges and nowhere near finished or 100% reliable; it does work for now and it is quick-and-dirty thrown together bit of code.

## Credits/History

Developed this class initially because of a project I was working where we had to run a very large data processing script; this script's tasks were easily split into sub-steps that could run in parallel, but the few PHP asynchronous frameworks I came across were underdeveloped and overcomplex for my needs. This one was born out of that and significantly reduced the amount of time our operation required to run.

Inspired initially by John Lim's technique described at http://phplens.com/phpeverywhere/?q=node/view/254.